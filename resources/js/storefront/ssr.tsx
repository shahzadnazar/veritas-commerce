import { createInertiaApp } from '@inertiajs/react';
import createServer from '@inertiajs/react/server';
import ReactDOMServer from 'react-dom/server';

/**
 * Server-side rendering, storefront only.
 *
 * Crawlers and first paint get complete HTML — the SEO requirement in the
 * specification. The seller and admin portals are behind auth and never
 * crawled, so they skip SSR entirely and halve the runtime surface.
 */
createServer((page) =>
    createInertiaApp({
        page,
        render: ReactDOMServer.renderToString,
        resolve: (name) => {
            const pages = import.meta.glob('./pages/**/*.tsx', { eager: true });

            return pages[`./pages/${name}.tsx`] as never;
        },
        setup: ({ App, props }) => <App {...props} />,
    }),
);
