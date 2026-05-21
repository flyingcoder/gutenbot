(function () {
    'use strict';

    var el              = wp.element.createElement;
    var useState        = wp.element.useState;
    var useRef          = wp.element.useRef;
    var useEffect       = wp.element.useEffect;
    var Fragment        = wp.element.Fragment;
    var registerPlugin  = wp.plugins.registerPlugin;
    var PluginSidebar   = wp.editPost.PluginSidebar;
    var PluginSidebarMoreMenuItem = wp.editPost.PluginSidebarMoreMenuItem;
    var TextareaControl = wp.components.TextareaControl;
    var Button          = wp.components.Button;
    var Notice          = wp.components.Notice;
    var Modal           = wp.components.Modal;
    var select          = wp.data.select;
    var dispatch        = wp.data.dispatch;

    var STAGES = [
        { key: 'parsing',  label: 'Parsing content'      },
        { key: 'layouts',  label: 'Fetching layouts'      },
        { key: 'ai',       label: 'Calling AI'            },
        { key: 'building', label: 'Building blocks'       },
    ];

    function ThinkingModal(props) {
        var doneStages = props.doneStages;   // array of stage keys completed
        var streamText = props.streamText;
        var plan       = props.plan;
        var error      = props.error;
        var onClose    = props.onClose;

        var preRef = useRef(null);

        useEffect(function () {
            if (preRef.current) {
                preRef.current.scrollTop = preRef.current.scrollHeight;
            }
        }, [streamText]);

        var canClose = !!(plan || error);

        var activeIndex = doneStages.length; // next stage being worked on

        return el(Modal, {
            title:          'GutenBot is thinking…',
            onRequestClose: canClose ? onClose : undefined,
            isDismissible:  canClose,
            style:          { maxWidth: '800px', width: '96vw' },
        },
            // Stage list + stream panel
            el('div', { style: { display: 'flex', gap: '24px', alignItems: 'flex-start' } },

                // Left: stage checklist
                el('ul', { style: { listStyle: 'none', margin: 0, padding: 0, width: '170px', flexShrink: 0 } },
                    STAGES.map(function (s, i) {
                        var done   = doneStages.indexOf(s.key) !== -1;
                        var active = !done && i === activeIndex;
                        return el('li', {
                            key:   s.key,
                            style: {
                                display:    'flex',
                                alignItems: 'center',
                                gap:        '8px',
                                padding:    '5px 0',
                                opacity:    (done || active) ? 1 : 0.35,
                                fontSize:   '13px',
                            },
                        },
                            el('span', { style: { width: '16px', textAlign: 'center', color: done ? '#00a32a' : '#888' } },
                                done ? '✓' : active ? '◌' : '○'
                            ),
                            s.label
                        );
                    })
                ),

                // Right: streaming output
                el('div', { style: { flex: 1, minWidth: 0 } },
                    error
                        ? el('div', {
                            style: {
                                padding: '12px 14px', background: '#fcf0f1',
                                borderLeft: '4px solid #d63638', borderRadius: '2px',
                                color: '#d63638', fontSize: '13px', lineHeight: '1.5',
                            },
                        }, error)
                        : el('pre', {
                            ref:   preRef,
                            style: {
                                background:  '#1e1e1e',
                                color:       '#d4d4d4',
                                padding:     '14px',
                                borderRadius: '4px',
                                fontSize:    '12px',
                                fontFamily:  'monospace',
                                overflowY:   'auto',
                                maxHeight:   '380px',
                                minHeight:   '140px',
                                margin:      0,
                                whiteSpace:  'pre-wrap',
                                wordBreak:   'break-all',
                                lineHeight:  '1.6',
                            },
                        }, streamText || (plan ? 'Response received.' : 'Waiting for AI response…'))
                )
            ),

            // Plan summary
            (plan && !error) ? el('div', {
                style: {
                    marginTop: '20px', padding: '10px 14px',
                    background: '#f0f6fc', borderRadius: '4px',
                    fontSize: '13px', lineHeight: '1.7',
                },
            },
                el('strong', null, plan.title),
                el('br'),
                el('span', { style: { color: '#666' } },
                    plan.page_type + ' — ' + plan.sections.join(', ')
                )
            ) : null,

            // Footer
            canClose ? el('div', { style: { marginTop: '16px', display: 'flex', justifyContent: 'flex-end' } },
                el(Button, { variant: error ? 'secondary' : 'primary', onClick: onClose },
                    error ? 'Close' : 'Done'
                )
            ) : null
        );
    }

    function GutenBotPanel() {
        var contentArr = useState('');
        var content    = contentArr[0];
        var setContent = contentArr[1];

        var modalArr = useState({ open: false, doneStages: [], streamText: '', plan: null, error: '' });
        var modal    = modalArr[0];
        var setModal = modalArr[1];

        function closeModal() {
            setModal({ open: false, doneStages: [], streamText: '', plan: null, error: '' });
        }

        function handleSseEvent(type, data) {
            if (type === 'stage') {
                setModal(function (p) { return Object.assign({}, p, { doneStages: p.doneStages.concat([data.stage]) }); });
            } else if (type === 'chunk') {
                setModal(function (p) { return Object.assign({}, p, { streamText: p.streamText + data.text }); });
            } else if (type === 'done') {
                var blocks = wp.blocks.parse(data.markup);
                dispatch('core/block-editor').resetBlocks(blocks);
                dispatch('core/editor').editPost({ title: data.plan.title });
                setModal(function (p) { return Object.assign({}, p, { plan: data.plan }); });
            } else if (type === 'error') {
                setModal(function (p) { return Object.assign({}, p, { error: data.message }); });
            }
        }

        function generate() {
            var trimmed = content.trim();
            if (!trimmed) return;

            var existing = select('core/block-editor').getBlocks();
            if (
                wp.blocks.serialize(existing).trim() !== '' &&
                !window.confirm('This will replace the current editor content. Continue?')
            ) {
                return;
            }

            setModal({ open: true, doneStages: [], streamText: '', plan: null, error: '' });

            // Fallback for browsers without ReadableStream
            if (!window.fetch || !window.ReadableStream) {
                wp.apiFetch({ path: gutenbotEditor.apiPath, method: 'POST', data: { content: trimmed } })
                    .then(function (res) {
                        dispatch('core/block-editor').resetBlocks(wp.blocks.parse(res.markup));
                        dispatch('core/editor').editPost({ title: res.title });
                        setModal(function (p) { return Object.assign({}, p, { plan: { title: res.title, page_type: res.page_type, sections: [] } }); });
                    })
                    .catch(function (err) {
                        setModal(function (p) { return Object.assign({}, p, { error: err.message || 'Generation failed.' }); });
                    });
                return;
            }

            var body = new URLSearchParams({
                action:  'gutenbot_stream_generate',
                nonce:   gutenbotEditor.streamNonce,
                content: trimmed,
            });

            fetch(gutenbotEditor.ajaxUrl, {
                method:  'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body:    body.toString(),
            }).then(function (response) {
                if (!response.ok) {
                    throw new Error('Server error (HTTP ' + response.status + ').');
                }

                var reader  = response.body.getReader();
                var decoder = new TextDecoder();
                var buf     = '';
                var gotDone = false;

                function pump() {
                    return reader.read().then(function (result) {
                        if (result.done) {
                            if (!gotDone) {
                                setModal(function (p) {
                                    return p.error || p.plan ? p : Object.assign({}, p, { error: 'Stream ended without a response. Check your API configuration.' });
                                });
                            }
                            return;
                        }

                        buf += decoder.decode(result.value, { stream: true });

                        var parts = buf.split('\n\n');
                        buf = parts.pop(); // keep incomplete trailing block

                        parts.forEach(function (block) {
                            var type    = 'message';
                            var dataStr = '';
                            block.split('\n').forEach(function (line) {
                                if (line.indexOf('event: ') === 0) { type    = line.slice(7).trim(); }
                                if (line.indexOf('data: ')  === 0) { dataStr = line.slice(6).trim(); }
                            });
                            if (!dataStr) return;
                            try {
                                var parsed = JSON.parse(dataStr);
                                if (type === 'done') { gotDone = true; }
                                handleSseEvent(type, parsed);
                            } catch (e) { /* malformed line, skip */ }
                        });

                        return pump();
                    });
                }

                return pump();
            }).catch(function (err) {
                setModal(function (p) { return Object.assign({}, p, { error: err.message || 'Streaming failed.' }); });
            });
        }

        return el(Fragment, null,
            el(PluginSidebarMoreMenuItem, { target: 'gutenbot-sidebar' }, 'GutenBot'),
            el(PluginSidebar, {
                name:  'gutenbot-sidebar',
                title: 'GutenBot — Generate Blocks',
            },
                el('div', { className: 'gutenbot-panel' },
                    el('p', { className: 'gutenbot-panel__hint' },
                        'Paste raw content below. GutenBot will generate structured blocks and suggest a page title.'
                    ),
                    el(TextareaControl, {
                        label:    'Raw Content',
                        value:    content,
                        onChange: setContent,
                        rows:     12,
                    }),
                    el('div', { className: 'gutenbot-panel__actions' },
                        el(Button, {
                            variant:  'primary',
                            onClick:  generate,
                            disabled: !content.trim(),
                        }, 'Generate Blocks')
                    )
                )
            ),
            modal.open ? el(ThinkingModal, {
                doneStages: modal.doneStages,
                streamText: modal.streamText,
                plan:       modal.plan,
                error:      modal.error,
                onClose:    closeModal,
            }) : null
        );
    }

    registerPlugin('gutenbot', { render: GutenBotPanel });
}());
