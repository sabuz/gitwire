import { ProviderIcon, providerLabel } from '../provider';

export default function SourceCell( { item } ) {
	const provider = item.provider ?? 'github';

	return (
		<span className={ `gitwire-badge gitwire-badge--${ provider }` }>
			<ProviderIcon provider={ provider } />
			{ providerLabel( provider ) }
		</span>
	);
}
