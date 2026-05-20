import wordpress from '@wordpress/eslint-plugin';

export default [
	...wordpress.configs.recommended,
	{
		languageOptions: {
			globals: {
				wp: 'readonly',
			},
		},
		rules: {
			// WordPress handles these via its own patterns
			'no-undef': 'off',
			'no-unused-vars': 'off',
			camelcase: 'off',
			'jsdoc/no-undefined-types': 'off',
			'no-duplicate-imports': 'off',
			// Not using TypeScript
			'@typescript-eslint/no-unused-vars': 'off',
			'@typescript-eslint/no-explicit-any': 'off',
		},
	},
];
