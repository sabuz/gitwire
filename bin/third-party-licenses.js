#!/usr/bin/env node
/**
 * Regenerates LICENSE-THIRD-PARTY.txt from whatever webpack actually bundles.
 *
 * Written from the real module list rather than package.json, because most of
 * what ends up in build/index.js arrives transitively: @wordpress/dataviews is
 * not on the externals list, so it and its dependency tree are compiled in.
 * MIT requires each of those notices to travel with the code.
 *
 * Run with `npm run licenses` after changing dependencies.
 */
const { execFileSync } = require( 'child_process' );
const fs = require( 'fs' );
const path = require( 'path' );

const ROOT = path.resolve( __dirname, '..' );
const MODULES = path.join( ROOT, 'node_modules' );
const OUT = path.join( ROOT, 'LICENSE-THIRD-PARTY.txt' );

// Licences that waive the notice requirement; listed but not quoted in full.
const NO_NOTICE_REQUIRED = [ '0BSD', 'CC0-1.0', 'Unlicense' ];

function bundledPackages() {
	const stats = execFileSync(
		'npx',
		[ 'webpack', '--config', 'webpack.config.js', '--json' ],
		{ cwd: ROOT, env: { ...process.env, NODE_ENV: 'production' }, maxBuffer: 256 * 1024 * 1024 }
	).toString();

	const found = new Set();
	for ( const mod of JSON.parse( stats ).modules || [] ) {
		const match = /node_modules\/((?:@[^/]+\/)?[^/]+)/.exec( mod.name || '' );
		if ( match ) {
			found.add( match[ 1 ] );
		}
	}
	return [ ...found ].sort();
}

function licenceText( pkgDir ) {
	const names = fs.readdirSync( pkgDir ).filter( ( f ) => /^(LICENSE|LICENCE|COPYING)/i.test( f ) );
	if ( ! names.length ) {
		return null;
	}
	return fs.readFileSync( path.join( pkgDir, names[ 0 ] ), 'utf8' ).trim();
}

/**
 * Reconstructs the MIT notice for a package that declares MIT but ships no
 * licence file. Several of the change-case family do exactly that.
 *
 * @param {Object} meta Parsed package.json.
 * @return {string|null} Licence text, or null when the author is unknown.
 */
function mitFallback( meta ) {
	if ( 'MIT' !== meta.license ) {
		return null;
	}
	const author = typeof meta.author === 'string' ? meta.author : meta.author && meta.author.name;
	if ( ! author ) {
		return null;
	}

	return `MIT License

Copyright (c) ${ author }

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.

(Reconstructed from the MIT declaration in package.json; this package ships no
licence file of its own.)`;
}

const sections = [];
const missing = [];

for ( const name of bundledPackages() ) {
	const dir = path.join( MODULES, name );
	let meta;
	try {
		meta = JSON.parse( fs.readFileSync( path.join( dir, 'package.json' ), 'utf8' ) );
	} catch ( e ) {
		continue;
	}

	// GPL-compatible and already covered by the plugin's own licence.
	if ( String( meta.license || '' ).startsWith( 'GPL-2.0' ) ) {
		continue;
	}

	const header = `${ meta.name } ${ meta.version }\nLicence: ${ meta.license || 'see below' }` +
		( meta.homepage ? `\nHomepage: ${ meta.homepage }` : '' );

	if ( NO_NOTICE_REQUIRED.includes( meta.license ) ) {
		sections.push( `${ header }\n(This licence does not require the notice to be reproduced.)` );
		continue;
	}

	const text = licenceText( dir ) || mitFallback( meta );
	if ( ! text ) {
		missing.push( `${ meta.name }@${ meta.version } (${ meta.license })` );
		continue;
	}
	sections.push( `${ header }\n\n${ text }` );
}

const divider = `\n\n${ '-'.repeat( 78 ) }\n\n`;
const preamble = `Third-party licences
====================

Gitwire itself is licensed GPL-2.0-or-later. Its admin bundle (build/index.js)
compiles in the libraries below, each under its own licence. Every one is
compatible with the GPL. WordPress packages loaded from core at runtime are not
listed, and neither are packages already licensed GPL-2.0-or-later.

Regenerate this file with \`npm run licenses\` after changing dependencies.
`;

fs.writeFileSync( OUT, `${ preamble }${ divider }${ sections.join( divider ) }\n` );

process.stdout.write( `Wrote ${ path.relative( ROOT, OUT ) } covering ${ sections.length } packages.\n` );
if ( missing.length ) {
	process.stdout.write( `\nNo licence file found, add by hand:\n  ${ missing.join( '\n  ' ) }\n` );
	process.exitCode = 1;
}
