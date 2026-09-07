/**
 * The pages the suite needs, and the block markup that makes each one.
 *
 * Kept in one place so global-setup can create them and the specs can name
 * them without repeating a slug in two files.
 */
export type Fixture = {
	slug: string;
	title: string;
	/** How many images the carousel should end up showing. */
	slides: number;
	content: ( ids: number[] ) => string;
};

const block = ( ids: number[], label = 'Test carousel' ) =>
	`<!-- wp:ulcar/carousel {"ids":[${ ids.join( ',' ) }],"ariaLabel":"${ label }"} /-->`;

export const FIXTURES: Record< string, Fixture > = {
	many: {
		slug: 'ulcar-e2e-many',
		title: 'ULCAR e2e — several images',
		slides: 5,
		content: ( ids ) => block( ids.slice( 0, 5 ) ),
	},
	one: {
		slug: 'ulcar-e2e-one',
		title: 'ULCAR e2e — a single image',
		slides: 1,
		content: ( ids ) => block( ids.slice( 0, 1 ) ),
	},
	none: {
		slug: 'ulcar-e2e-none',
		title: 'ULCAR e2e — no image',
		slides: 0,
		content: () => block( [] ),
	},
	twice: {
		slug: 'ulcar-e2e-twice',
		title: 'ULCAR e2e — two carousels',
		slides: 5,
		content: ( ids ) =>
			`${ block( ids.slice( 0, 3 ), 'First' ) }${ block( ids.slice( 3, 5 ), 'Second' ) }`,
	},
	hostile: {
		slug: 'ulcar-e2e-hostile',
		title: 'ULCAR e2e — a hostile accessible name',
		slides: 2,
		content: ( ids ) =>
			`<!-- wp:ulcar/carousel {"ids":[${ ids
				.slice( 0, 2 )
				.join( ',' ) }],"ariaLabel":"\\u0022 onload=\\u0022alert(1)"} /-->`,
	},
};
