/**
 * Single source of truth for the documentation order.
 *
 * Filenames keep their NN- prefix so GitHub renders docs/ in reading order;
 * `slug` strips the prefix so site URLs stay stable when pages are reordered.
 * `blurb` is what the end-of-page "Next" call to action shows, so it reads as
 * a reason to continue rather than a bare page title.
 */
export type DocPage = {
    file: string
    text: string
    blurb: string
}

export type DocSection = {
    text: string
    pages: DocPage[]
}

export const sections: DocSection[] = [
    {
        text: 'Getting started',
        pages: [
            {
                file: '01-why-richter',
                text: 'Why Richter?',
                blurb: 'What a report tells you that a diff does not, and what the package refuses to guess at.',
            },
            {
                file: '02-installation',
                text: 'Installation',
                blurb: 'Require the package, check the PHP and Laravel versions, and publish the config.',
            },
            {
                file: '03-project-setup',
                text: 'Set up your project',
                blurb: 'Teach Richter your app\'s shape with an agent skill, or with two prompts you can paste anywhere.',
            },
        ],
    },
    {
        text: 'Change impact',
        pages: [
            {
                file: '04-detect-changes',
                text: 'Detecting change impact',
                blurb: 'Run the main command, read the report, and see how each entry point is reached.',
            },
            {
                file: '05-report-annotations',
                text: 'Report annotations',
                blurb: 'Security exposure, Pennant gates, payload parity, and middleware group membership.',
            },
            {
                file: '06-output-formats',
                text: 'Output formats',
                blurb: 'Markdown for pull requests, HTML for a visual report, and the semver-governed JSON contract.',
            },
            {
                file: '07-risk-levels',
                text: 'Risk levels',
                blurb: 'The hazard tiers, the reach matrix, and the ladder that turns them into a level.',
            },
            {
                file: '08-ci-gating',
                text: 'Gating in CI',
                blurb: 'Turn the advisory report into a pull-request check with --fail-on and --fail-on-unresolved.',
            },
        ],
    },
    {
        text: 'Commands',
        pages: [
            {
                file: '09-impact',
                text: 'Blast radius of a symbol',
                blurb: 'List a symbol\'s callers, its dependencies, and the entry surfaces behind them.',
            },
            {
                file: '10-trace',
                text: 'Shortest path between symbols',
                blurb: 'Answer "how does this even reach that?" with the shortest call chain.',
            },
            {
                file: '11-affected-tests',
                text: 'Affected-test selection',
                blurb: 'Turn the diff\'s reach into a test selection with a fail-safe exit-code contract.',
            },
        ],
    },
    {
        text: 'Digging deeper',
        pages: [
            {
                file: '12-frontend',
                text: 'Frontend changes',
                blurb: 'Bridge Wayfinder and Ziggy references to backend routes, in both directions.',
            },
            {
                file: '13-mcp-server',
                text: 'MCP server',
                blurb: 'Give a coding agent every analysis as a read-only tool, without shelling out.',
            },
            {
                file: '14-graph-cache',
                text: 'Graph cache',
                blurb: 'How the fingerprinted cache works, when a scoped rebuild engages, and why it refuses.',
            },
            {
                file: '15-coverage',
                text: 'Coverage beyond Laravel Brain',
                blurb: 'The edges a route-anchored analysis misses, and the three known limits.',
            },
        ],
    },
    {
        text: 'Reference',
        pages: [
            {
                file: '16-configuration',
                text: 'Configuration reference',
                blurb: 'Every key in config/richter.php, with defaults and what each one changes.',
            },
            {
                file: '17-benchmark',
                text: 'Benchmarking',
                blurb: 'Score accuracy against replayable history before and after a change to the model.',
            },
            {
                file: '18-troubleshooting',
                text: 'Troubleshooting',
                blurb: 'A symptom index: empty reports, UNRESOLVED files, saturated risk levels, exit 2.',
            },
        ],
    },
]

/** Flat reading order — drives rewrites, the sidebar, and the "Next" call to action. */
export const pages: DocPage[] = sections.flatMap(section => section.pages)

export const slug = (file: string) => file.replace(/^\d+-/, '')

export const link = (file: string) => `/${slug(file)}`
