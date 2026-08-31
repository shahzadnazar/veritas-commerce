import js from '@eslint/js';
import tseslint from 'typescript-eslint';
import reactHooks from 'eslint-plugin-react-hooks';

export default tseslint.config(
    { ignores: ['public/**', 'vendor/**', 'node_modules/**', 'bootstrap/**'] },
    js.configs.recommended,
    ...tseslint.configs.recommended,
    {
        files: ['resources/js/**/*.{ts,tsx}'],
        plugins: { 'react-hooks': reactHooks },
        rules: {
            ...reactHooks.configs.recommended.rules,
            '@typescript-eslint/no-explicit-any': 'error',
            '@typescript-eslint/consistent-type-imports': 'error',
            // The design system is the only source of colour. Raw hex in a
            // component is the drift the Phase 6 review warned about.
            'no-restricted-syntax': [
                'error',
                {
                    selector: "Literal[value=/^#(?:[0-9a-fA-F]{3}){1,2}$/]",
                    message:
                        'No raw hex colours. Take colour from a design-system token (var(--vc-*) or tokens.ts).',
                },
            ],
        },
    },
);
