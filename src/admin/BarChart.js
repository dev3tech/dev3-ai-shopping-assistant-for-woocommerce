import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { formatNumber } from '../core3/ui';

/**
 * Single-series daily bar chart (one hue, rounded data-ends on the baseline, 2px gaps, recessive
 * axis, per-bar hover/focus tooltip, a screen-reader table).
 *
 * @param {Object} props       Props.
 * @param {Array}  props.data  [ { day, conversations } ].
 * @param {string} props.label Series name.
 * @return {Element} Chart.
 */
export default function BarChart( { data = [], label } ) {
	const [ hover, setHover ] = useState( null );
	const max = Math.max( 1, ...data.map( ( d ) => d.conversations ) );
	const nice = max <= 5 ? max : Math.ceil( max / 5 ) * 5;
	const fmt = ( day ) => {
		const dt = new Date( day + 'T00:00:00' );
		return isNaN( dt ) ? day : dt.toLocaleDateString( undefined, { month: 'short', day: 'numeric' } );
	};
	const active = hover !== null ? data[ hover ] : null;
	return (
		<div className="dev3sa-daychart">
			<div className="dev3sa-daychart__plot" onMouseLeave={ () => setHover( null ) }>
				<div className="dev3sa-daychart__grid" aria-hidden="true">
					<span>{ formatNumber( nice ) }</span>
					<span>{ formatNumber( Math.round( nice / 2 ) ) }</span>
					<span>0</span>
				</div>
				<div className="dev3sa-daychart__cols">
					{ data.map( ( d, i ) => (
						<button
							type="button"
							key={ d.day }
							className={ 'dev3sa-daychart__col' + ( hover === i ? ' is-active' : '' ) }
							onMouseEnter={ () => setHover( i ) }
							onFocus={ () => setHover( i ) }
							onBlur={ () => setHover( null ) }
							aria-label={ `${ fmt( d.day ) }: ${ d.conversations } ${ label }` }
						>
							<span className="dev3sa-daychart__bar" style={ { height: `${ ( d.conversations / nice ) * 100 }%` } } />
						</button>
					) ) }
				</div>
				{ active && (
					<div className="dev3sa-daychart__tip" role="status" style={ { left: `${ ( ( hover + 0.5 ) / data.length ) * 100 }%` } }>
						<strong>{ formatNumber( active.conversations ) }</strong> { label }
						<span>{ fmt( active.day ) }</span>
					</div>
				) }
			</div>
			<div className="dev3sa-daychart__axis" aria-hidden="true">
				<span>{ data.length ? fmt( data[ 0 ].day ) : '' }</span>
				<span>{ data.length ? fmt( data[ data.length - 1 ].day ) : '' }</span>
			</div>
			<table className="screen-reader-text">
				<caption>{ label }</caption>
				<thead>
					<tr>
						<th scope="col">{ __( 'Day', 'dev3-ai-shopping-assistant-for-woocommerce' ) }</th>
						<th scope="col">{ label }</th>
					</tr>
				</thead>
				<tbody>
					{ data.map( ( d ) => (
						<tr key={ d.day }>
							<td>{ d.day }</td>
							<td>{ d.conversations }</td>
						</tr>
					) ) }
				</tbody>
			</table>
		</div>
	);
}
