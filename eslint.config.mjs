import { FlatCompat } from '@eslint/eslintrc';
import path from 'path';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath( import.meta.url );
const __dirname = path.dirname( __filename );

const compat = new FlatCompat( {
	baseDirectory: __dirname,
} );

export default [
	...compat.extends( 'plugin:@wordpress/eslint-plugin/recommended' ),
	{
		languageOptions: {
			globals: {
				wp: 'readonly',
			},
		},
		rules: {
			camelcase: 'off',
			'jsdoc/no-undefined-types': 'off',
			'no-duplicate-imports': 'off',
			'no-undef': 'off',
			'no-unused-vars': 'off',
			'@typescript-eslint/no-explicit-any': 'off',
			'@typescript-eslint/no-unused-vars': 'off',
		},
	},
];
