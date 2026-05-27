import { __ } from '@wordpress/i18n';
import { Button } from '@wordpress/components';

/**
 * Clickable branch badge that opens the branch switcher modal.
 *
 * @param {Object}   props              Component props.
 * @param {Object}   props.item         Installed repository record.
 * @param {Function} props.onOpenBranch Callback to open the branch modal.
 * @return {JSX.Element} The rendered branch cell.
 */
export default function BranchCell( { item, onOpenBranch } ) {
	return (
		<Button
			className="gitwire-branch-btn"
			size="compact"
			variant="link"
			onClick={ () => onOpenBranch( item ) }
		>
			<span className="gitwire-branch-btn__text">{ item.branch }</span>
		</Button>
	);
}
