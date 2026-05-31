import { registerPlugin } from '@wordpress/plugins';
import { PluginSidebar } from '@wordpress/edit-post';
import { useState, useCallback } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import { TextareaControl, SelectControl, Button, Spinner, PanelBody, Notice } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { parse } from '@wordpress/blocks';

const PAGE_TYPES = [
	{ label: 'Landing Page', value: 'landing' },
	{ label: 'About',        value: 'about' },
	{ label: 'Services',     value: 'services' },
	{ label: 'Contact',      value: 'contact' },
	{ label: 'Blog Post',    value: 'blog' },
	{ label: 'Custom',       value: 'custom' },
];

function SectionPreview( { section, rating, onRate } ) {
	const id = section.section_id;
	return (
		<div style={ { borderTop: '1px solid #e0e0e0', paddingTop: '10px', marginTop: '10px' } }>
			<div style={ { display: 'flex', justifyContent: 'space-between', alignItems: 'center' } }>
				<span style={ { fontSize: '11px', textTransform: 'uppercase', color: '#757575', fontWeight: 600, letterSpacing: '0.05em' } }>
					{ section.section_type ?? '—' }
				</span>
				{ id && (
					<span style={ { display: 'flex', gap: '2px' } }>
						<Button
							size="small"
							variant={ rating === 1 ? 'primary' : 'tertiary' }
							onClick={ () => onRate( id, 1 ) }
							aria-label="Accept section"
						>
							👍
						</Button>
						<Button
							size="small"
							variant={ rating === -1 ? 'primary' : 'tertiary' }
							onClick={ () => onRate( id, -1 ) }
							aria-label="Reject section"
						>
							👎
						</Button>
					</span>
				) }
			</div>
			{ section.headline && (
				<p style={ { margin: '4px 0 0', fontWeight: 500, fontSize: '13px' } }>{ section.headline }</p>
			) }
		</div>
	);
}

function GutenBotSidebar() {
	const [ content,  setContent  ] = useState( '' );
	const [ pageType, setPageType ] = useState( 'landing' );
	const [ loading,  setLoading  ] = useState( false );
	const [ sections, setSections ] = useState( [] );
	const [ markup,   setMarkup   ] = useState( '' );
	const [ error,    setError    ] = useState( '' );
	const [ ratings,  setRatings  ] = useState( {} );

	const { insertBlocks } = useDispatch( 'core/block-editor' );
	const postId = useSelect( ( select ) => select( 'core/editor' ).getCurrentPostId(), [] );

	const handleGenerate = useCallback( async () => {
		if ( ! content.trim() ) return;
		setLoading( true );
		setError( '' );
		setSections( [] );
		setMarkup( '' );
		setRatings( {} );

		try {
			const res = await apiFetch( {
				path:   '/ai-pagebuilder/v1/generate',
				method: 'POST',
				data:   { content, page_type: pageType, post_id: postId },
			} );

			if ( res.success ) {
				setSections( res.data?.sections ?? [] );
				setMarkup( res.data?.full_markup ?? '' );
			} else {
				setError( res.error ?? 'Generation failed. Please try again.' );
			}
		} catch ( err ) {
			setError( err?.message ?? 'An unexpected error occurred.' );
		} finally {
			setLoading( false );
		}
	}, [ content, pageType, postId ] );

	const handleInsert = useCallback( () => {
		if ( ! markup ) return;
		insertBlocks( parse( markup ) );
	}, [ markup, insertBlocks ] );

	const handleRate = useCallback( async ( sectionId, rating ) => {
		setRatings( ( prev ) => ( { ...prev, [ sectionId ]: rating } ) );
		try {
			await apiFetch( {
				path:   '/ai-pagebuilder/v1/rate',
				method: 'POST',
				data:   { section_id: sectionId, rating, was_edited: false },
			} );
		} catch {
			// non-critical — keep optimistic rating state
		}
	}, [] );

	return (
		<PluginSidebar name="gutenbot-sidebar" title="GutenBot">

			<PanelBody title="Generate" initialOpen={ true }>
				<TextareaControl
					label="Raw content"
					help="Paste your page outline or brief (max 5000 characters)."
					value={ content }
					onChange={ setContent }
					rows={ 6 }
				/>
				<SelectControl
					label="Page type"
					value={ pageType }
					options={ PAGE_TYPES }
					onChange={ setPageType }
				/>
				<div style={ { marginTop: '8px' } }>
					<Button
						variant="primary"
						onClick={ handleGenerate }
						disabled={ loading || ! content.trim() }
						style={ { width: '100%', justifyContent: 'center' } }
					>
						{ loading
							? ( <><Spinner style={ { marginRight: '6px' } } />Generating…</> )
							: 'Generate'
						}
					</Button>
				</div>

				{ error && (
					<Notice
						status="error"
						isDismissible={ true }
						onRemove={ () => setError( '' ) }
						style={ { marginTop: '12px' } }
					>
						{ error }
					</Notice>
				) }
			</PanelBody>

			{ sections.length > 0 && (
				<>
					<PanelBody title={ `Sections (${ sections.length })` } initialOpen={ true }>
						{ sections.map( ( section, i ) => (
							<SectionPreview
								key={ section.section_id ?? i }
								section={ section }
								rating={ ratings[ section.section_id ] }
								onRate={ handleRate }
							/>
						) ) }
					</PanelBody>

					<div style={ { padding: '0 16px 16px' } }>
						<Button
							variant="primary"
							onClick={ handleInsert }
							style={ { width: '100%', justifyContent: 'center' } }
						>
							Insert into Editor
						</Button>
					</div>
				</>
			) }

		</PluginSidebar>
	);
}

registerPlugin( 'gutenbot', { render: GutenBotSidebar } );
