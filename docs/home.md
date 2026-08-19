---
layout: home

hero:
  name: Richter
  text: Measure the reach of a code change
  tagline: Static impact analysis for Laravel. See the entry points a diff reaches, the ones no test references, and an advisory risk level — before the review starts.
  image:
    src: /logo.svg
    alt: Richter
  actions:
    - theme: brand
      text: Get started
      link: /installation
    - theme: alt
      text: Why Richter?
      link: /why-richter
    - theme: alt
      text: GitHub
      link: https://github.com/SanderMuller/richter

features:
  - title: Member-level impact
    details: A one-method change seeds that method, not the whole class. Routes, controllers, jobs, listeners, policies, resources, Blade views, and Eloquent relations are all in the graph.
    link: /why-richter
  - title: Honest degradation
    details: A change the graph cannot place reads UNRESOLVED, never a falsely reassuring "no impact". A coverage gap costs reach; it never reports something as unaffected.
    link: /detect-changes#when-a-report-of-nothing-is-correct
  - title: Test-coverage prompts
    details: Every reached entry point is tagged as test-referenced or not. An entry point whose behaviour you changed with nothing referencing it is a place to add a test.
    link: /detect-changes#test-reference-tags
  - title: Blast radius on demand
    details: Before a refactor, list a symbol's callers, its dependencies, and the entry surfaces behind them — or trace the shortest chain between two symbols.
    link: /impact
  - title: Affected-test selection
    details: Turn the diff's reach into a test selection, with an exit-code contract that fails toward running the full suite whenever it cannot be trusted.
    link: /affected-tests
  - title: Built for coding agents
    details: A local MCP server exposes every analysis read-only, so an agent can work with the graph mid-review. The markdown report is ready to post as a PR comment.
    link: /mcp-server
---

## What a report looks like

```text
Entry points reached: 2
  - command::categories:sync  (app/Console/Commands/SyncCategories.php)  [test-referenced]
  - route::PATCH::/api/posts/{post}  (routes/api.php:41)  [⚠ no test references this]  [authed]

Findings (in the changed source itself):
  ! app/Models/Post.php: eager-load string 'ownerprofile': segment 'ownerprofile' is not a
    method on any model — check the relation name

Impacted nodes: 7
Risk: MEDIUM (advisory)
```

Install it as a dev dependency and run one command on your branch. The analysis is static: it never executes your application's routes, jobs, or commands.

```bash
composer require --dev sandermuller/richter
php artisan richter:detect-changes
```

## Where to next

<HomeNextSteps />
