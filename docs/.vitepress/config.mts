import { defineConfig } from 'vitepress'
import { link, pages, sections } from './pages'

const description = 'Measure the magnitude of impact of code changes in a Laravel codebase: the entry points a diff reaches, the ones no test references, and an advisory risk level.'

export default defineConfig({
    title: 'Richter',
    description,
    base: '/richter/',
    cleanUrls: true,
    lastUpdated: true,

    head: [
        ['link', { rel: 'icon', type: 'image/svg+xml', href: '/richter/logo.svg' }],
        ['meta', { name: 'theme-color', content: '#d97706' }],
        ['meta', { property: 'og:type', content: 'website' }],
        ['meta', { property: 'og:title', content: 'Richter' }],
        ['meta', { property: 'og:description', content: description }],
        ['meta', { property: 'og:image', content: 'https://sandermuller.github.io/richter/header.png' }],
        ['meta', { name: 'twitter:card', content: 'summary_large_image' }],
        ['meta', { name: 'twitter:image', content: 'https://sandermuller.github.io/richter/header.png' }],
    ],

    // README.md is the GitHub-facing folder index; the site's home is home.md.
    srcExclude: ['README.md'],

    rewrites: {
        'home.md': 'index.md',
        ...Object.fromEntries(pages.map(page => [`${page.file}.md`, `${page.file.replace(/^\d+-/, '')}.md`])),
    },

    markdown: {
        // Markdown links target the NN-prefixed source files so they work on
        // GitHub; strip the prefix at render time to match the rewritten routes.
        config(md) {
            const defaultRender = md.renderer.rules.link_open
                ?? ((tokens, idx, options, _env, self) => self.renderToken(tokens, idx, options))
            md.renderer.rules.link_open = (tokens, idx, options, env, self) => {
                const href = tokens[idx].attrGet('href')
                if (href && /^(\.\/)?\d+-/.test(href)) {
                    tokens[idx].attrSet('href', href.replace(/^(\.\/)?\d+-/, '$1'))
                }
                return defaultRender(tokens, idx, options, env, self)
            }
        },
    },

    themeConfig: {
        logo: '/logo.svg',

        nav: [
            { text: 'Guide', link: link('01-why-richter') },
            { text: 'Configuration', link: link('16-configuration') },
            { text: 'Packagist', link: 'https://packagist.org/packages/sandermuller/richter' },
        ],

        sidebar: sections.map(section => ({
            text: section.text,
            items: section.pages.map(page => ({ text: page.text, link: link(page.file) })),
        })),

        socialLinks: [
            { icon: 'github', link: 'https://github.com/SanderMuller/richter' },
        ],

        docFooter: {
            next: false,
        },

        editLink: {
            pattern: 'https://github.com/SanderMuller/richter/edit/main/docs/:path',
            text: 'Edit this page on GitHub',
        },

        search: {
            provider: 'local',
        },

        outline: {
            level: [2, 3],
        },
    },
})
