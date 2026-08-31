import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import '../../css/app.css';

/**
 * The storefront entry point.
 *
 * One bundle per area, so a storefront visitor never downloads seller or
 * admin code, and an area's pages are code-split within it.
 */
void createInertiaApp({
    resolve: (name) => {
        const pages = import.meta.glob('./pages/**/*.tsx');
        const page = pages[`./pages/${name}.tsx`];

        if (!page) {
            throw new Error(`Page not found: ${name} (storefront)`);
        }

        return page();
    },
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
});
