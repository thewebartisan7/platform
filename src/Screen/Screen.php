<?php

declare(strict_types=1);

namespace Orchid\Screen;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Routing\UrlRoutable;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Orchid\Platform\Http\Controllers\Controller;
use Orchid\Support\Facades\Dashboard;
use ReflectionClass;
use ReflectionException;
use ReflectionParameter;
use Throwable;

/**
 * Class Screen.
 */
abstract class Screen extends Controller
{
    use Commander;

    /**
     * The number of predefined arguments in the route.
     *
     * Example: dashboard/my-screen/{method?}
     */
    private const COUNT_ROUTE_VARIABLES = 1;

    /**
     * Display header name.
     *
     * @return string
     */
    public function name(): ?string
    {
        return $this->name ?? null;
    }

    /**
     * Permission
     *
     * @return iterable|null
     */
    public function permission(): ?iterable
    {
        return isset($this->permission)
            ? Arr::wrap($this->permission)
            : null;
    }

    /**
     * Display header description.
     *
     * @return string
     */
    public function description(): ?string
    {
        return $this->description ?? null;
    }

    /**
     * Button commands.
     *
     * @return Action[]
     */
    public function commandBar()
    {
        return [];
    }

    /**
     * Views.
     *
     * @return Layout[]
     */
    abstract public function layout(): iterable;

    /**
     * @param \Orchid\Screen\Repository $repository
     *
     * @return View
     */
    public function build(Repository $repository)
    {
        return LayoutFactory::blank([
            $this->layout(),
        ])->build($repository);
    }

    /**
     * @param string $method
     * @param string $slug
     *
     * @throws Throwable
     *
     * @return View
     *
     */
    public function asyncBuild(string $method, string $slug)
    {
        Dashboard::setCurrentScreen($this);

        abort_unless(method_exists($this, $method), 404, "Async method: {$method} not found");

        $query = $this->callMethod($method, request()->all());
        $source = new Repository($query);

        /** @var Layout $layout */
        $layout = collect($this->layout())
            ->map(function ($layout) {
                return is_object($layout) ? $layout : resolve($layout);
            })
            ->map(function (Layout $layout) use ($slug) {
                return $layout->findBySlug($slug);
            })
            ->filter()
            ->whenEmpty(function () use ($slug) {
                abort(404, "Async template: {$slug} not found");
            })
            ->first();

        return $layout->currentAsync()->build($source);
    }

    /**
     * @param array $httpQueryArguments
     *
     * @throws ReflectionException
     *
     * @return Factory|\Illuminate\View\View
     *
     */
    public function view(array $httpQueryArguments = [])
    {
        $query = $this->callMethod('query', $httpQueryArguments);

        $key = $this->fill($query);
        $repository = new Repository($query);

        $commandBar = $this->buildCommandBar($repository);
        $layouts = $this->build($repository);

        return view('platform::layouts.base', [
            'name'                => $this->name(),
            'description'         => $this->description(),
            'commandBar'          => $commandBar,
            'layouts'             => $layouts,
            'key'                 => $key,
            'formValidateMessage' => $this->formValidateMessage(),
        ]);
    }

    /**
     * @return array
     */
    public function getAvailableProperties(): array
    {
        return collect((new \ReflectionObject($this))->getProperties())
            ->filter(function (\ReflectionProperty $property) {
                return $property->isPublic() && ! $property->isStatic();
            })
            ->map(function (\ReflectionProperty $property) {
                return $property->getName();
            })
            ->toArray();
    }

    /**
     * @param array $query
     *
     * @throws \Exception
     *
     * @return string
     */
    public function fill(array $query): string
    {
        $value = collect($query)->only($this->getAvailableProperties())
            ->each(function ($value, $key) {
                data_set($this, $key, $value);
            });

        $key = sprintf("screen-%s-%s", Auth::id(), Str::uuid());

        cache()->remember($key, config('session.lifetime') * 60, function () use ($value) {
            return $value;
        });

        return $key;
    }

    /**
     *
     */
    public function fillSession(): void
    {
        $property = cache()->pull(request()->input('_screen'), collect());

        $property->each(function ($value, $key) {
            data_set($this, $key, $value);
        });
    }

    /**
     * @param mixed ...$parameters
     *
     * @throws ReflectionException
     * @throws Throwable
     *
     * @return Factory|View|\Illuminate\View\View|mixed
     *
     */
    public function handle(...$parameters)
    {
        Dashboard::setCurrentScreen($this);
        abort_unless($this->checkAccess(), 403);

        if (request()->isMethod('GET')) {
            return $this->redirectOnGetMethodCallOrShowView($parameters);
        }

        $method = Route::current()->parameter('method', Arr::last($parameters));

        $parameters = array_diff(
            $parameters,
            [$method]
        );

        $query = request()->query();
        $query = ! is_array($query) ? [] : $query;

        $parameters = array_filter($parameters);
        $parameters = array_merge($query, $parameters);

        $this->fillSession();

        $response = $this->callMethod($method, $parameters);

        return $response ?? back();
    }

    /**
     * @param string $method
     * @param array  $httpQueryArguments
     *
     * @throws ReflectionException
     *
     * @return array
     *
     */
    private function reflectionParams(string $method, array $httpQueryArguments = []): array
    {
        $class = new ReflectionClass($this);

        if (! is_string($method)) {
            return [];
        }

        if (! $class->hasMethod($method)) {
            return [];
        }

        $parameters = $class->getMethod($method)->getParameters();

        return collect($parameters)
            ->map(function ($parameter, $key) use ($httpQueryArguments) {
                return $this->bind($key, $parameter, $httpQueryArguments);
            })
            ->all();
    }

    /**
     * It takes the serial number of the argument and the required parameter.
     * To convert to object.
     *
     * @param int                 $key
     * @param ReflectionParameter $parameter
     * @param array               $httpQueryArguments
     *
     * @throws BindingResolutionException
     *
     * @return mixed
     *
     */
    private function bind(int $key, ReflectionParameter $parameter, array $httpQueryArguments)
    {
        $class = $parameter->getType() && ! $parameter->getType()->isBuiltin()
            ? $parameter->getType()->getName()
            : null;

        $original = array_values($httpQueryArguments)[$key] ?? null;

        if ($class === null || is_object($original)) {
            return $original;
        }

        $instance = resolve($class);

        if ($original === null || ! is_a($instance, UrlRoutable::class)) {
            return $instance;
        }

        $model = $instance->resolveRouteBinding($original);

        throw_if(
            $model === null && ! $parameter->isDefaultValueAvailable(),
            (new ModelNotFoundException())->setModel($class, [$original])
        );

        optional(Route::current())->setParameter($parameter->getName(), $model);

        return $model;
    }

    /**
     * @return bool
     */
    private function checkAccess(): bool
    {
        return collect($this->permission())
            ->map(static function ($item) {
                return optional(Auth::user())->hasAccess($item);
            })
            ->whenEmpty(function (Collection $permission) {
                return $permission->push(true);
            })
            ->contains(true);
    }

    /**
     * @return string
     */
    public function formValidateMessage(): string
    {
        return __('Please check the entered data, it may be necessary to specify in other languages.');
    }

    /**
     * Defines the URL to represent
     * the page based on the calculation of link arguments.
     *
     * @param array $httpQueryArguments
     *
     * @throws ReflectionException
     *
     * @return Factory|RedirectResponse|\Illuminate\View\View
     *
     */
    protected function redirectOnGetMethodCallOrShowView(array $httpQueryArguments)
    {
        $expectedArg = count(Route::current()->getCompiled()->getVariables()) - self::COUNT_ROUTE_VARIABLES;
        $realArg = count($httpQueryArguments);

        if ($realArg <= $expectedArg) {
            return $this->view($httpQueryArguments);
        }

        array_pop($httpQueryArguments);

        return redirect()->action([static::class, 'handle'], $httpQueryArguments);
    }

    /**
     * @param string $method
     * @param array  $parameters
     *
     * @throws ReflectionException
     *
     * @return mixed
     *
     */
    private function callMethod(string $method, array $parameters = [])
    {
        return call_user_func_array([$this, $method],
            $this->reflectionParams($method, $parameters)
        );
    }

    /**
     * Get can transfer to the screen only
     * user-created methods available in it.
     *
     * @array
     */
    public static function getAvailableMethods(): array
    {
        return array_diff(
            get_class_methods(static::class), // Custom methods
            get_class_methods(self::class),   // Basic methods
            ['query']                                   // Except methods
        );
    }
}
