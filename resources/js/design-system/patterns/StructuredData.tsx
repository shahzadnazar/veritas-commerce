import { Head } from '@inertiajs/react';

/**
 * Emits JSON-LD the server assembled.
 *
 * The shape is built in PHP from database rows and passed through
 * untouched — deliberately, so no component can add a rating or a price
 * the catalogue cannot support. Nothing here decides what to claim.
 */
export function StructuredData({ documents }: { documents: unknown[] }) {
    if (documents.length === 0) {
        return null;
    }

    return (
        <Head>
            {documents.map((document, index) => (
                <script
                    key={index}
                    type="application/ld+json"
                    // The payload is server-generated JSON, never user
                    // input reaching the DOM as markup.
                    dangerouslySetInnerHTML={{ __html: JSON.stringify(document) }}
                />
            ))}
        </Head>
    );
}
