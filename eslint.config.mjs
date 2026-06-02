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
			camelcase: 'off',
			'import/no-extraneous-dependencies': 'off',
			'jsdoc/no-undefined-types': 'off',
			'no-duplicate-imports': 'off',
			'no-undef': 'off',
			'no-unused-vars': 'off',
			'@typescript-eslint/no-explicit-any': 'off',
			'@typescript-eslint/no-unused-vars': 'off',
		},
	},
];
