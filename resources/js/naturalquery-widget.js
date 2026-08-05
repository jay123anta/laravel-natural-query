/*!
 * NaturalQuery Widget — drop-in text + voice query UI for laravel-natural-query.
 *
 * Zero dependencies. Injects its own styles. Talks to the package's REST API.
 *
 * Usage:
 *   <div id="nq"></div>
 *   <script src="/naturalquery/widget.js"></script>
 *   <script>NaturalQueryWidget.mount('#nq', { baseUrl: '/naturalquery' });</script>
 *
 * Or simply use the Blade component: <x-naturalquery::widget />
 *
 * Voice strategy:
 *   1. Browser SpeechRecognition (Chrome/Edge/Safari) — free, works with EVERY
 *      LLM provider because transcription happens client-side → /text endpoint.
 *   2. Fallback: MediaRecorder → base64 audio → /voice endpoint (server-side
 *      transcription; requires a provider with supportsVoice(), e.g. Gemini).
 *   3. Neither available → mic button hidden; text input always works.
 */
(function (global) {
    'use strict';

    var DEFAULTS = {
        baseUrl: '/naturalquery',
        csrfToken: null,          // auto-read from <meta name="csrf-token"> when null
        title: 'Ask your data',
        placeholder: 'Type a question or use the microphone…',
        language: 'en-IN',        // BCP-47 locale for speech recognition + TTS
        voice: true,              // show mic when supported
        serverVoice: true,        // allow MediaRecorder → /voice fallback
        tts: true,                // read answers aloud (toggleable by user)
        autoSpeak: false,         // speak automatically after each answer
        conversation: true,       // use /conversation endpoint (follow-up support)
        examples: [],             // array of example query strings
        maxBarRows: 12,           // rows rendered as bars before table-only
        themeColor: '#2563eb',
        footerNote: 'AI-generated · please verify important figures',
        scheme: null              // fixed scheme hint (single-dataset apps)
    };

    // ---------------------------------------------------------------- helpers

    function h(tag, cls, text) {
        var el = document.createElement(tag);
        if (cls) el.className = cls;
        if (text !== undefined && text !== null) el.textContent = text;
        return el;
    }

    function fmtNum(v) {
        if (v === null || v === undefined || v === '') return '—';
        var n = Number(v);
        if (isNaN(n)) return String(v);
        var opts = Math.abs(n) < 100 && n % 1 !== 0 ? { maximumFractionDigits: 2 } : { maximumFractionDigits: 2 };
        try { return n.toLocaleString('en-IN', opts); } catch (e) { return String(n); }
    }

    function titleCase(s) {
        return String(s).replace(/_/g, ' ').replace(/\b\w/g, function (c) { return c.toUpperCase(); });
    }

    function isNumericCol(rows, col) {
        for (var i = 0; i < rows.length; i++) {
            var v = rows[i][col];
            if (v === null || v === undefined || v === '') continue;
            return !isNaN(Number(v));
        }
        return false;
    }

    function uuid() {
        return 'nq-' + Math.random().toString(36).slice(2) + Date.now().toString(36);
    }

    // ---------------------------------------------------------------- styles

    var STYLE_ID = 'nq-widget-styles';

    function injectStyles(theme) {
        if (document.getElementById(STYLE_ID)) return;
        var css = ''
            + '.nq-widget{--nq:' + theme + ';font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;max-width:860px;margin:0 auto;color:#1f2937}'
            + '.nq-widget *{box-sizing:border-box}'
            + '.nq-card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;box-shadow:0 1px 3px rgba(0,0,0,.06);padding:18px;margin-bottom:14px}'
            + '.nq-title{font-size:1.05rem;font-weight:600;margin:0 0 12px}'
            + '.nq-row{display:flex;gap:8px;align-items:center}'
            + '.nq-input{flex:1;border:1px solid #d1d5db;border-radius:10px;padding:11px 14px;font-size:.95rem;outline:none;min-width:0}'
            + '.nq-input:focus{border-color:var(--nq);box-shadow:0 0 0 3px color-mix(in srgb,var(--nq) 15%,transparent)}'
            + '.nq-btn{border:none;border-radius:10px;padding:11px 16px;font-size:.95rem;cursor:pointer;background:var(--nq);color:#fff;display:inline-flex;align-items:center;gap:6px;white-space:nowrap}'
            + '.nq-btn:disabled{opacity:.55;cursor:not-allowed}'
            + '.nq-btn-ghost{background:#f3f4f6;color:#374151}'
            + '.nq-mic{position:relative;width:44px;height:44px;justify-content:center;padding:0;border-radius:50%}'
            + '.nq-mic.nq-listening{background:#dc2626;animation:nq-pulse 1.2s infinite}'
            + '@keyframes nq-pulse{0%{box-shadow:0 0 0 0 rgba(220,38,38,.5)}70%{box-shadow:0 0 0 12px rgba(220,38,38,0)}100%{box-shadow:0 0 0 0 rgba(220,38,38,0)}}'
            + '.nq-interim{margin-top:10px;font-size:.88rem;color:#6b7280;font-style:italic;min-height:1.2em}'
            + '.nq-examples{display:flex;flex-wrap:wrap;gap:6px;margin-top:12px}'
            + '.nq-chip{border:1px solid #e5e7eb;background:#f9fafb;border-radius:999px;padding:6px 12px;font-size:.82rem;color:#374151;cursor:pointer}'
            + '.nq-chip:hover{border-color:var(--nq);color:var(--nq)}'
            + '.nq-loading{display:flex;align-items:center;gap:10px;color:#6b7280;font-size:.9rem;padding:6px 2px}'
            + '.nq-spinner{width:18px;height:18px;border:2.5px solid #e5e7eb;border-top-color:var(--nq);border-radius:50%;animation:nq-spin .7s linear infinite}'
            + '@keyframes nq-spin{to{transform:rotate(360deg)}}'
            + '.nq-answer{display:flex;gap:10px;align-items:flex-start}'
            + '.nq-answer-text{flex:1;font-size:.98rem;line-height:1.5;margin:0}'
            + '.nq-speak{background:none;border:1px solid #e5e7eb;border-radius:8px;width:34px;height:34px;cursor:pointer;color:#6b7280;flex:none}'
            + '.nq-speak.nq-speaking{color:var(--nq);border-color:var(--nq)}'
            + '.nq-big{font-size:2.1rem;font-weight:700;color:var(--nq);text-align:center;padding:14px 0 4px}'
            + '.nq-big-label{text-align:center;color:#6b7280;font-size:.85rem;padding-bottom:10px}'
            + '.nq-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-top:6px}'
            + '.nq-stat{background:#f9fafb;border:1px solid #eef0f3;border-radius:10px;padding:12px;text-align:center}'
            + '.nq-stat-val{font-size:1.25rem;font-weight:700;color:#111827}'
            + '.nq-stat-lbl{font-size:.75rem;color:#6b7280;margin-top:3px}'
            + '.nq-bars{margin-top:8px}'
            + '.nq-bar-row{display:grid;grid-template-columns:minmax(90px,180px) 1fr 90px;gap:8px;align-items:center;padding:4px 0;font-size:.86rem}'
            + '.nq-bar-lbl{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:#374151}'
            + '.nq-bar-track{background:#f3f4f6;border-radius:6px;height:20px;overflow:hidden}'
            + '.nq-bar-fill{height:100%;background:var(--nq);border-radius:6px;min-width:2px;transition:width .5s ease}'
            + '.nq-bar-val{text-align:right;font-weight:600;color:#111827}'
            + '.nq-table-wrap{overflow-x:auto;margin-top:10px}'
            + '.nq-table{width:100%;border-collapse:collapse;font-size:.86rem}'
            + '.nq-table th{text-align:left;padding:8px 10px;background:#f9fafb;border-bottom:2px solid #e5e7eb;color:#374151;white-space:nowrap}'
            + '.nq-table td{padding:7px 10px;border-bottom:1px solid #f1f3f5}'
            + '.nq-table td.nq-num,.nq-table th.nq-num{text-align:right}'
            + '.nq-insights{display:flex;flex-wrap:wrap;gap:8px;margin-top:12px}'
            + '.nq-insight{background:color-mix(in srgb,var(--nq) 8%,white);border:1px solid color-mix(in srgb,var(--nq) 22%,white);border-radius:8px;padding:6px 12px;font-size:.8rem;color:#1f2937}'
            + '.nq-clarify{margin-top:8px}'
            + '.nq-clarify-msg{font-size:.92rem;color:#374151;margin-bottom:10px}'
            + '.nq-options{display:flex;flex-wrap:wrap;gap:8px}'
            + '.nq-error{background:#fef2f2;border:1px solid #fecaca;color:#b91c1c;border-radius:10px;padding:12px 14px;font-size:.9rem}'
            + '.nq-footer{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-top:12px;padding-top:10px;border-top:1px solid #f1f3f5;font-size:.75rem;color:#9ca3af;flex-wrap:wrap}'
            + '.nq-hidden{display:none!important}';
        var style = h('style');
        style.id = STYLE_ID;
        style.textContent = css;
        document.head.appendChild(style);
    }

    // ---------------------------------------------------------------- widget

    function Widget(root, opts) {
        this.opts = Object.assign({}, DEFAULTS, opts || {});
        this.root = root;
        this.sessionId = uuid();
        this.listening = false;
        this.recognition = null;
        this.mediaRecorder = null;
        this.speaking = false;
        this.csrf = this.opts.csrfToken
            || (document.querySelector('meta[name="csrf-token"]') || {}).content
            || null;
        injectStyles(this.opts.themeColor);
        this.build();
        this.setupVoice();
    }

    Widget.prototype.build = function () {
        var o = this.opts, self = this;
        this.root.classList.add('nq-widget');

        var card = h('div', 'nq-card');
        if (o.title) card.appendChild(h('p', 'nq-title', o.title));

        var row = h('div', 'nq-row');
        this.input = h('input', 'nq-input');
        this.input.type = 'text';
        this.input.placeholder = o.placeholder;
        this.input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') self.submit();
        });
        row.appendChild(this.input);

        this.micBtn = h('button', 'nq-btn nq-mic', null);
        this.micBtn.type = 'button';
        this.micBtn.title = 'Voice input';
        this.micBtn.innerHTML = micSvg();
        this.micBtn.addEventListener('click', function () { self.toggleVoice(); });
        this.micBtn.classList.add('nq-hidden'); // shown by setupVoice() when supported
        row.appendChild(this.micBtn);

        this.sendBtn = h('button', 'nq-btn', 'Ask');
        this.sendBtn.type = 'button';
        this.sendBtn.addEventListener('click', function () { self.submit(); });
        row.appendChild(this.sendBtn);
        card.appendChild(row);

        this.interim = h('div', 'nq-interim', '');
        card.appendChild(this.interim);

        if (o.examples && o.examples.length) {
            var ex = h('div', 'nq-examples');
            o.examples.forEach(function (q) {
                var chip = h('button', 'nq-chip', q);
                chip.type = 'button';
                chip.addEventListener('click', function () {
                    self.input.value = q;
                    self.submit();
                });
                ex.appendChild(chip);
            });
            card.appendChild(ex);
        }
        this.root.appendChild(card);

        this.resultArea = h('div');
        this.root.appendChild(this.resultArea);
    };

    // -------------------------------------------------------------- voice

    Widget.prototype.setupVoice = function () {
        if (!this.opts.voice) return;
        var SR = global.SpeechRecognition || global.webkitSpeechRecognition;
        var self = this;

        if (SR) {
            this.recognition = new SR();
            this.recognition.lang = this.opts.language;
            this.recognition.interimResults = true;
            this.recognition.continuous = false;
            this.recognition.onresult = function (e) {
                var finalText = '', interimText = '';
                for (var i = 0; i < e.results.length; i++) {
                    if (e.results[i].isFinal) finalText += e.results[i][0].transcript;
                    else interimText += e.results[i][0].transcript;
                }
                self.interim.textContent = interimText || finalText;
                if (finalText) self.input.value = finalText.trim();
            };
            this.recognition.onend = function () {
                self.setListening(false);
                self.interim.textContent = '';
                if (self.input.value.trim()) self.submit();
            };
            this.recognition.onerror = function () { self.setListening(false); };
            this.voiceMode = 'browser';
            this.micBtn.classList.remove('nq-hidden');
            return;
        }

        if (this.opts.serverVoice && global.MediaRecorder && navigator.mediaDevices) {
            this.voiceMode = 'server';
            this.micBtn.classList.remove('nq-hidden');
        }
    };

    Widget.prototype.toggleVoice = function () {
        if (this.listening) return this.stopVoice();
        if (this.voiceMode === 'browser') {
            try { this.recognition.start(); this.setListening(true); } catch (e) { /* already started */ }
        } else if (this.voiceMode === 'server') {
            this.startRecording();
        }
    };

    Widget.prototype.stopVoice = function () {
        if (this.voiceMode === 'browser' && this.recognition) {
            try { this.recognition.stop(); } catch (e) {}
        } else if (this.mediaRecorder && this.mediaRecorder.state === 'recording') {
            this.mediaRecorder.stop();
        }
        this.setListening(false);
    };

    Widget.prototype.startRecording = function () {
        var self = this;
        navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
            var chunks = [];
            self.mediaRecorder = new MediaRecorder(stream);
            self.mediaRecorder.ondataavailable = function (e) { chunks.push(e.data); };
            self.mediaRecorder.onstop = function () {
                stream.getTracks().forEach(function (t) { t.stop(); });
                var blob = new Blob(chunks, { type: self.mediaRecorder.mimeType || 'audio/webm' });
                var reader = new FileReader();
                reader.onloadend = function () {
                    var base64 = String(reader.result).split(',')[1];
                    self.submitVoice(base64, blob.type);
                };
                reader.readAsDataURL(blob);
            };
            self.mediaRecorder.start();
            self.setListening(true);
            self.interim.textContent = 'Recording… click the mic again to stop.';
            setTimeout(function () {
                if (self.mediaRecorder && self.mediaRecorder.state === 'recording') self.mediaRecorder.stop();
            }, 15000); // hard cap 15s
        }).catch(function () {
            self.renderError('Microphone access was denied.');
        });
    };

    Widget.prototype.setListening = function (on) {
        this.listening = on;
        this.micBtn.classList.toggle('nq-listening', on);
        if (!on) this.interim.textContent = '';
    };

    // -------------------------------------------------------------- network

    Widget.prototype.headers = function () {
        var hd = { 'Content-Type': 'application/json', 'Accept': 'application/json' };
        if (this.csrf) hd['X-CSRF-TOKEN'] = this.csrf;
        return hd;
    };

    Widget.prototype.submit = function (schemeOverride) {
        var text = this.input.value.trim();
        if (!text) return;
        var self = this, o = this.opts;
        this.stopSpeaking();
        this.renderLoading();
        this.sendBtn.disabled = true;

        var useConversation = o.conversation && !schemeOverride;
        var url = o.baseUrl + (useConversation ? '/conversation' : '/text');
        var body = { text: text };
        if (useConversation) body.session_id = this.sessionId;
        var scheme = schemeOverride || o.scheme;
        if (scheme) body.scheme = scheme;

        fetch(url, { method: 'POST', headers: this.headers(), body: JSON.stringify(body), credentials: 'same-origin' })
            .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, json: j }; }); })
            .then(function (res) { self.renderResponse(res.json); })
            .catch(function () { self.renderError('Could not reach the query service. Please try again.'); })
            .finally(function () { self.sendBtn.disabled = false; });
    };

    Widget.prototype.submitVoice = function (audioBase64, mimeType) {
        var self = this, o = this.opts;
        this.renderLoading('Transcribing audio…');
        fetch(o.baseUrl + '/voice', {
            method: 'POST',
            headers: this.headers(),
            body: JSON.stringify({ audio: audioBase64, mime_type: mimeType, scheme: o.scheme || undefined }),
            credentials: 'same-origin'
        })
            .then(function (r) { return r.json(); })
            .then(function (json) { self.renderResponse(json); })
            .catch(function () { self.renderError('Voice processing failed. Please type your question instead.'); });
    };

    // -------------------------------------------------------------- rendering

    Widget.prototype.clearResult = function () { this.resultArea.innerHTML = ''; };

    Widget.prototype.renderLoading = function (msg) {
        this.clearResult();
        var card = h('div', 'nq-card');
        var l = h('div', 'nq-loading');
        l.appendChild(h('div', 'nq-spinner'));
        l.appendChild(h('span', null, msg || 'Thinking…'));
        card.appendChild(l);
        this.resultArea.appendChild(card);
    };

    Widget.prototype.renderError = function (msg) {
        this.clearResult();
        var card = h('div', 'nq-card');
        card.appendChild(h('div', 'nq-error', msg));
        this.resultArea.appendChild(card);
    };

    Widget.prototype.renderResponse = function (data) {
        if (!data || typeof data !== 'object') return this.renderError('Unexpected response from server.');
        if (data.status === 'error') return this.renderError(data.error || 'The query could not be processed.');
        if (data.status === 'clarification_needed') return this.renderClarification(data);
        if (data.status !== 'success') return this.renderError('Unexpected response status.');

        this.clearResult();
        var card = h('div', 'nq-card');
        var self = this;

        // Answer line + TTS control
        if (data.answer) {
            var ans = h('div', 'nq-answer');
            ans.appendChild(h('p', 'nq-answer-text', data.answer));
            if (this.opts.tts && global.speechSynthesis && (data.speech_text || data.answer)) {
                this.speakBtn = h('button', 'nq-speak');
                this.speakBtn.type = 'button';
                this.speakBtn.title = 'Read aloud';
                this.speakBtn.innerHTML = speakerSvg();
                this.speakBtn.addEventListener('click', function () {
                    self.speaking ? self.stopSpeaking() : self.speak(data.speech_text || data.answer);
                });
                ans.appendChild(this.speakBtn);
            }
            card.appendChild(ans);
        }

        var rows = data.rows || [];
        var viz = data.visualization || 'table';

        if (viz === 'message' || rows.length === 0) {
            // answer text already covers it
        } else if (rows.length === 1) {
            this.renderSingleRow(card, rows[0]);
        } else if (viz === 'bar' && rows.length <= this.opts.maxBarRows) {
            this.renderBars(card, rows);
            if (rows.length > 5) this.renderTable(card, rows);
        } else {
            this.renderTable(card, rows);
        }

        // Insights
        if (data.insights && typeof data.insights === 'object') {
            var ins = h('div', 'nq-insights');
            var count = 0;
            Object.keys(data.insights).forEach(function (k) {
                var v = data.insights[k];
                if (v === null || v === undefined || typeof v === 'object') return;
                ins.appendChild(h('span', 'nq-insight', titleCase(k) + ': ' + v));
                count++;
            });
            if (count) card.appendChild(ins);
        }

        // Footer: provenance + timing. (No SQL here — server-side logs only.)
        var foot = h('div', 'nq-footer');
        foot.appendChild(h('span', null, this.opts.footerNote));
        var meta = data.metadata || {};
        var right = [];
        if (meta.cache_hit) right.push('cached');
        if (meta.processing_time_ms) right.push(Math.round(meta.processing_time_ms) + ' ms');
        if (right.length) foot.appendChild(h('span', null, right.join(' · ')));
        card.appendChild(foot);

        this.resultArea.appendChild(card);

        if (this.opts.autoSpeak && this.opts.tts && global.speechSynthesis) {
            this.speak(data.speech_text || data.answer || '');
        }
    };

    Widget.prototype.renderSingleRow = function (card, row) {
        var keys = Object.keys(row);
        var numeric = keys.filter(function (k) { return !isNaN(Number(row[k])) && row[k] !== null && row[k] !== ''; });
        if (keys.length <= 2 && numeric.length >= 1) {
            var valKey = numeric[numeric.length - 1];
            card.appendChild(h('div', 'nq-big', fmtNum(row[valKey])));
            card.appendChild(h('div', 'nq-big-label', titleCase(valKey)));
            return;
        }
        var grid = h('div', 'nq-cards');
        keys.forEach(function (k) {
            var stat = h('div', 'nq-stat');
            stat.appendChild(h('div', 'nq-stat-val', fmtNum(row[k])));
            stat.appendChild(h('div', 'nq-stat-lbl', titleCase(k)));
            grid.appendChild(stat);
        });
        card.appendChild(grid);
    };

    Widget.prototype.renderBars = function (card, rows) {
        var cols = Object.keys(rows[0] || {});
        var labelCol = cols.find(function (c) { return !isNumericCol(rows, c); }) || cols[0];
        var valueCol = cols.find(function (c) { return c !== labelCol && isNumericCol(rows, c); });
        if (!valueCol) return this.renderTable(card, rows);

        var max = Math.max.apply(null, rows.map(function (r) { return Math.abs(Number(r[valueCol]) || 0); })) || 1;
        var wrap = h('div', 'nq-bars');
        rows.forEach(function (r) {
            var line = h('div', 'nq-bar-row');
            line.appendChild(h('div', 'nq-bar-lbl', String(r[labelCol] !== null && r[labelCol] !== undefined ? r[labelCol] : '—')));
            var track = h('div', 'nq-bar-track');
            var fill = h('div', 'nq-bar-fill');
            fill.style.width = Math.max(2, Math.round(Math.abs(Number(r[valueCol]) || 0) / max * 100)) + '%';
            track.appendChild(fill);
            line.appendChild(track);
            line.appendChild(h('div', 'nq-bar-val', fmtNum(r[valueCol])));
            wrap.appendChild(line);
        });
        card.appendChild(wrap);
    };

    Widget.prototype.renderTable = function (card, rows) {
        var cols = Object.keys(rows[0] || {});
        var wrap = h('div', 'nq-table-wrap');
        var table = h('table', 'nq-table');
        var thead = h('thead'), tr = h('tr');
        cols.forEach(function (c) {
            var th = h('th', isNumericCol(rows, c) ? 'nq-num' : null, titleCase(c));
            tr.appendChild(th);
        });
        thead.appendChild(tr);
        table.appendChild(thead);
        var tbody = h('tbody');
        rows.forEach(function (r) {
            var trb = h('tr');
            cols.forEach(function (c) {
                var numeric = isNumericCol(rows, c);
                trb.appendChild(h('td', numeric ? 'nq-num' : null, numeric ? fmtNum(r[c]) : String(r[c] === null || r[c] === undefined ? '—' : r[c])));
            });
            tbody.appendChild(trb);
        });
        table.appendChild(tbody);
        wrap.appendChild(table);
        card.appendChild(wrap);
    };

    Widget.prototype.renderClarification = function (data) {
        this.clearResult();
        var self = this;
        var card = h('div', 'nq-card nq-clarify');
        card.appendChild(h('div', 'nq-clarify-msg', data.message || 'Please clarify your question.'));
        var optsWrap = h('div', 'nq-options');

        (data.alternatives || []).forEach(function (alt) {
            var b = h('button', 'nq-btn nq-btn-ghost', alt.scheme_name || alt.scheme_key);
            b.type = 'button';
            b.addEventListener('click', function () { self.submit(alt.scheme_key); });
            optsWrap.appendChild(b);
        });
        (data.available_metrics || []).forEach(function (m) {
            var label = typeof m === 'string' ? m : (m.name || m.key);
            var b = h('button', 'nq-btn nq-btn-ghost', titleCase(label));
            b.type = 'button';
            if (typeof m === 'object' && m.description) b.title = m.description;
            b.addEventListener('click', function () {
                var current = self.input.value.trim();
                // Clicking twice must not build "best? revenue revenue".
                if (current.toLowerCase().indexOf(label.toLowerCase()) === -1) {
                    self.input.value = current + ' ' + label;
                }
                self.submit();
            });
            optsWrap.appendChild(b);
        });

        if (optsWrap.children.length) card.appendChild(optsWrap);
        this.resultArea.appendChild(card);
    };

    // -------------------------------------------------------------- tts

    Widget.prototype.speak = function (text) {
        if (!text || !global.speechSynthesis) return;
        this.stopSpeaking();
        var self = this;
        var u = new SpeechSynthesisUtterance(text);
        u.lang = this.opts.language;
        u.rate = 0.95;
        u.onstart = function () { self.setSpeaking(true); };
        u.onend = function () { self.setSpeaking(false); };
        u.onerror = function () { self.setSpeaking(false); };
        global.speechSynthesis.speak(u);
    };

    Widget.prototype.stopSpeaking = function () {
        if (global.speechSynthesis) global.speechSynthesis.cancel();
        this.setSpeaking(false);
    };

    Widget.prototype.setSpeaking = function (on) {
        this.speaking = on;
        if (this.speakBtn) this.speakBtn.classList.toggle('nq-speaking', on);
    };

    // -------------------------------------------------------------- svg icons

    function micSvg() {
        return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/></svg>';
    }

    function speakerSvg() {
        return '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M15.54 8.46a5 5 0 0 1 0 7.07"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14"/></svg>';
    }

    // -------------------------------------------------------------- public API

    global.NaturalQueryWidget = {
        mount: function (selector, options) {
            var el = typeof selector === 'string' ? document.querySelector(selector) : selector;
            if (!el) throw new Error('NaturalQueryWidget: mount target not found: ' + selector);
            return new Widget(el, options);
        }
    };
})(window);
