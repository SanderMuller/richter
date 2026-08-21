# Coverage beyond Laravel Brain

Richter adds two things over [Laravel Brain](https://github.com/laramint/laravel-brain) alone: the tooling (CLI, MCP, and CI/PR review) and wider graph coverage. On coverage, it traces the edges a route-anchored analysis misses.

Brain traces some of these too (view composition, resource references, queue dispatches, observers, facade resolution), but the overlap is narrower than it looks. Brain's analysis starts at routes; richter's tracers read files. For a class no route reaches, Brain draws no edges at all. Where the two agree, it is because that code happened to be route-reachable.

- queue dispatches, including unresolvable ones. A class that dispatches itself (`self::dispatch()`, `static::dispatch()`) resolves to the class declaring it, instead of to the literal name PHP leaves in the source; `static::` lands on the declaring class as the honest floor, and `override` edges carry a subclass from there. Three shapes are refused rather than guessed, and keep the unresolved name they always had: `parent::`, whose class is not known where the edge is drawn; a file declaring more than one class-like, where `self` cannot be tied to one of them; and a trait, where `self` is the *consuming* class at runtime, so naming the trait would name a class that never dispatched. `new self()` draws nothing, since constructing the enclosing class is not dispatching it, while `new static()` keeps its edge, since late static binding can build a subclass;
- container bindings and interface implementations;
- config-keyed class registries: a subsystem dispatched by looking a class up in `config/x.php` (`config("calculators.{$id}")`) is reachable from nothing otherwise, so every class in it reports no callers however central it is. A literal key is resolved against the config file and links only what that key's value names, so an ordinary setting read (`config('app.timezone')`) draws nothing even when its file names classes elsewhere, and a surface behind such a read is a caller like any other. A key that cannot be enumerated (interpolated, or in a file whose array is built at runtime) falls back to every app class the file names. That fan-out carries reach without counting toward the risk level, and the surfaces behind it are listed as context rather than as callers: they are identical for every class the file names, so they cannot tell one change from another;
- views rendered by a class no route reaches: Brain connects a controller to `view('posts.show')` by walking the body a route led it to, so a Livewire component, Filament page, mailable or action class leaves the view it renders with no caller, and every diff touching that view reads UNRESOLVED. The name is written out in the source either way and is read directly: as the `view('…')` / `View::make('…')` call, or as the `protected static string $view = '…'` declaration a page component uses when its base class does the rendering. That second form renders nothing itself, so a call-only read covers none of them, and it anchors on the class because there is no method to name. Literal names only, and only when the Blade file exists here: a package-namespaced name (`mail::message`) resolves elsewhere;
- controllers routed by the legacy string action (`'PostController@show'` rather than `[PostController::class, 'show']`): the action arrives under-qualified, either as a bare basename or partially qualified when a `->namespace()` group applied a namespace without the root a provider adds. The partial form is FQCN-shaped enough to become a node of its own beside the real class. The route then reaches a class nothing else in the graph refers to, so the controller reports no entry surface while every code edge hangs off the class the route never reaches. Both forms are rewritten onto the class they name, provided exactly one candidate matches; an ambiguous one is left alone rather than pointed at the wrong class;
- polymorphic overrides: a call on an abstract-class or interface method also reaches the concrete overrides in its subclasses/implementors, so a handler chosen at runtime (a config-registry driver, a factory, `app()->make($runtimeClass)`) is not left orphaned. It takes a call to open that door. A node the walk reaches only through type structure does not fan out to every implementor, and the same refusal covers the descendants that inherit an ancestor member rather than override it. The change implements an interface, the interface declares the method, and the implementors behind it are cousins of the change: they neither call it nor run it. A path that carries a call still fans out, wherever it ends;
- static calls: `Foo::bar()`, the shape a static registry, named constructor or factory is reached through, which a `new`-oriented trace leaves with no node at all;
- calls a class makes on itself: `$this->doTheWork()` links the two members. The receiver is the enclosing class by definition, so this needs no type inference — and a class no route reaches had no call edges at all before it. The method may be inherited rather than declared here, in which case the inherited-method lane connects it to the ancestor whose body runs. A method no app class in the chain declares is a framework method (`$this->hasMany(…)`) and draws nothing. A call on a property, a parameter or a local is a receiver-typing problem and is not drawn;
- inherited methods: a method a class inherits without overriding runs in the parent, so the parent is connected to the subclass its callers actually go through (the same declaring-class resolution the constant lane does);
- calls through an application facade: a facade is an app class like any other, so `Reports::generate()` otherwise stops at a member the facade does not declare, leaving the class its accessor names reachable from nothing. An accessor naming its concrete (`return ReportGenerator::class`) is read directly; one naming a container key (`return 'reports'`) is resolved through the bindings registered under `app/Providers`, so the older facade idiom reaches its class too. A key nothing there binds, or one two providers bind to different classes, draws nothing;
- class-constant and enum-case reads: a change to a constant or enum case pins to the methods that read it (resolved to the declaring class, so an inherited constant still connects), instead of coarsely flagging the whole class;
- policy references (`$user->can(PostPolicy::UPDATE, …)` and `@can(...)` in Blade);
- object construction: `new SomeClass(...)` inside a method links that method to `SomeClass::__construct`, so a value object, DTO or element class with no route of its own is still reached from the code that builds it. The target is the CONSTRUCTOR rather than the class on purpose: a class-level link would make every method of a widely-constructed class reach every place that builds one, while depending on a constructor is the narrower and truer claim. An unqualified name that resolves against the file's own namespace without existing (a `new DateTimeImmutable()` missing its import) is left alone rather than pointed at an invented node;
- API resource composition;
- custom validation rules;
- trait usage;
- eager-load relation strings, whole paths rather than first segments: `with('comments.author')` links `Post::comments` and `Comment::author`, because the relation index says which model each segment returns. A segment on a model the index knows, naming a relation that model does not have, is reported against that model rather than against every model at once;
- relation methods to the models they return: `Post::comments` links to `Comment`, so a change to the model a relation arrives at reaches the code that walks or eager-loads it. Brain's model-to-model edge is class to class, which stops one hop short of the member that reads the relation;
- relations walked in a body: `$this->post->author` links the relation methods it walks. The receiver has to say what it is somewhere in the source: `$this`, a typed property or parameter, `new Post`, a static that returns one model (`first`, `create`, `updateOrCreate` and the rest of that family, plus `find` with a literal id), a `@var` docblock on the statement, or a local bound to any of those. The chain ends at the first hop that does not resolve, and it also ends after a to-many hop, because `$post->comments` is a collection and what follows it belongs to the collection, not to `Comment`;
- view-to-view includes;
- frontend endpoint references: Wayfinder imports, Ziggy calls, endpoint literals in changed TS/JS/Vue files and Blade inline scripts (opt-in, see [Frontend changes](12-frontend.md)).

## Known limits

Seven limits are worth knowing before you read a report against them.

Relation traversals need a root the source types. Richter follows `$this->post->author` hop by hop, because a relation names its target in the `hasMany(Comment::class)` argument, but the value the chain starts from has to say what it is: `$this`, a typed property or parameter, `new Post`, a model-returning static, a `@var` docblock on the statement, or a local bound to one of those. An untyped property, a `mixed`, a union type, and a value taken from a query builder or a collection all end the chain before it starts.

A binding the code only sometimes makes is refused rather than assumed. An assignment inside an `if`, a loop, a `try` or a ternary clears the variable instead of typing it, because a sibling branch may bind another model and the source order cannot say which one ran. A closure keeps its bindings to itself and does not read the ones around it. `find($id)` resolves only for a literal id, since Laravel returns a collection for an array one. Each of those costs a chain rather than risking a wrong one.

A class reached only through a static call has the methods those calls name read, which connects what they construct and connects an inherited method's work through the subclass. The rest of the class stays unread by default: a method nobody calls statically. Set `richter.second_hop` to `'class'` to read those too, measured at ~8.0s against the default's ~4.5s on a 4,000-file app, or to `false` to trade the reach back for the build time.

The fourth limit is an accessor that picks at runtime. A facade's `getFacadeAccessor()` is carried over when it returns a single `::class`, and resolved against the bindings in `app/Providers` when it returns a single container key. Two `::class` returns, or a key two providers bind to different classes, name no one class. The wrong guess sends a reviewer to the wrong file, so richter draws nothing.

A provider that CONFIGURES a route it does not declare is connected to nothing. A rate limiter
registered with `RateLimiter::for('login', …)`, or any behaviour a provider attaches to routes
declared elsewhere, decides what those routes do — and richter draws no edge from the provider to
them, because nothing in the source names them. The provider is placed, so the change is not
UNRESOLVED; it simply reaches no entry surface.

Since 0.40 that costs a level as well as reach. A changed class reaching no entry point is graded on
whether a runnable test imports the class itself, and tests that exercise a throttled route import
the route helpers rather than the provider — so a provider change with real behavioural tests behind
it reads `medium`, "no test referencing them". The statement is true about what richter can see. The
fix is coverage, not a cap: until an edge connects the two, a test that names the class is the only
thing that changes the answer, and shaping tests to satisfy the tool is the wrong trade.

A route file is compared for guards, not for exposure. `routes/*.php` is read route by route for the
guard middleware a route lost, which is a hazard. What it does not do is grade the route's exposure at
base against its exposure at head — Brain classifies the head graph only, and the base side would need
a second graph build. So a route that was authenticated and is now public is caught by the middleware
it lost, and not by the exposure it gained. A closure route in a prefixed group also grades
`no-known-path`, because the URI written in the file is not the URI that was registered. Two routes
that share a verb and a URI as written — the same path under two different `prefix()` or `domain()`
groups — have their guards read together, so a guard dropped from one while the other keeps it is
missed. And a guard lost in one route file while another route file gains one — `routes/web.php` and
`routes/api.php` in the same diff — reads as the same guard moving and is suppressed. A middleware
group written in a shape richter does not read reports a finding rather than a comparison, and an
application subclass of a framework guard middleware matches no name in a group.

An application that schedules through a legacy `app/Console/Kernel.php` gets no `schedule::` nodes at
all. Brain models the schedule from the Laravel 11+ `routes/console.php` form; from a Console Kernel
it yields command nodes only. So a change to the schedule itself — a cron time, a frequency — reaches
no entry point and grades on the `Kernel` class, and every schedule-shaped answer richter can give is
invisible on that structure. `php artisan schedule:list` still shows the schedule; the graph does not
carry it.

The last limit costs an explanation rather than reach. Where a node is reached first through type structure and later through a call, the chain the report prints for it keeps the first route, which can name an override hop the walk refuses on that route. The reached set, the impacted count and the risk level are unaffected; only the chain drawn through such a node is.

The first five limits cost reach: fewer edges, so fewer entry points behind a change. None of them
makes a change report as unaffected — a change the graph cannot place reads UNRESOLVED, and one that
is placed but reaches nothing reads `medium`, never `low`. Reach lost to a coverage limit is reported
as ignorance, never as safety.
