# Coverage beyond Laravel Brain

Richter adds two things over [Laravel Brain](https://github.com/laramint/laravel-brain) alone: the tooling (CLI, MCP, and CI/PR review) and wider graph coverage. On coverage, it traces the edges a route-anchored analysis misses.

Brain traces some of these too (view composition, resource references, queue dispatches, observers, facade resolution), but the overlap is narrower than it looks. Brain's analysis starts at routes; richter's tracers read files. For a class no route reaches, Brain draws no edges at all. Where the two agree, it is because that code happened to be route-reachable.

- queue dispatches, including unresolvable ones;
- container bindings and interface implementations;
- config-keyed class registries: a subsystem dispatched by looking a class up in `config/x.php` (`config("calculators.{$id}")`) is reachable from nothing otherwise, so every class in it reports no callers however central it is. The lookup is linked to every app class the file names — the keys are usually built at runtime, the class list is not. That fan-out is an over-approximation like `override`, so it carries reach and entry-point discovery without counting toward the risk level;
- polymorphic overrides: a call on an abstract-class or interface method also reaches the concrete overrides in its subclasses/implementors, so a handler chosen at runtime (a config-registry driver, a factory, `app()->make($runtimeClass)`) is not left orphaned;
- static calls: `Foo::bar()`, the shape a static registry, named constructor or factory is reached through, which a `new`-oriented trace leaves with no node at all;
- inherited methods: a method a class inherits without overriding runs in the parent, so the parent is connected to the subclass its callers actually go through (the same declaring-class resolution the constant lane does);
- calls through an application facade: a facade is an app class like any other, so `Reports::generate()` otherwise stops at a member the facade does not declare, leaving the class its accessor names reachable from nothing;
- class-constant and enum-case reads: a change to a constant or enum case pins to the methods that read it (resolved to the declaring class, so an inherited constant still connects), instead of coarsely flagging the whole class;
- policy references (`$user->can(PostPolicy::UPDATE, …)` and `@can(...)` in Blade);
- API resource composition;
- custom validation rules;
- trait usage;
- eager-load relation strings;
- view-to-view includes;
- frontend endpoint references: Wayfinder imports, Ziggy calls, endpoint literals in changed TS/JS/Vue files and Blade inline scripts (opt-in, see [Frontend changes](frontend.md)).

## Known limits

Three limits on that list, all easy to infer past.

Relations are traced as declarations, not traversals. Richter links `Post` to `Comment` because the relation is declared on the model, but it does not follow a method body walking `$this->a->b->c->d` to arrive at one; resolving that needs the type of every hop.

The second limit used to be larger. A class reached only through a static call had every method body left unread, because Laravel Brain's call-chain analysis is anchored on routes, so a `new SomeDto(...)` inside such a class drew no edge at all. Richter now reads the methods those static calls name, which is enough to connect what they construct and to connect an inherited method's work through the subclass. What stays unread is the rest of the class: a method nobody calls statically. Set `richter.second_hop` to `false` to trade that reach back for build time (~4.5s on a 4,000-file app).

The third is the facade whose `getFacadeAccessor()` does not name one class. `return ReportGenerator::class` names its concrete and is carried over. `return 'reports'` names a container binding richter does not keep, and an accessor that picks between two classes at runtime names both; in either case the wrong guess sends a reviewer to the wrong file, so richter draws nothing.

All three are gaps in reach, never in honesty: nothing is reported as unaffected on their account.
