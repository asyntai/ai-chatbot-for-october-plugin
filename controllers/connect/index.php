<?php $connected = $siteId !== ''; ?>
<style>
    .asyntai-wrap{max-width:820px;padding:20px}
    .asyntai-panel{background:#fff;border:1px solid #e0e0e0;padding:20px 22px;margin:0 0 16px}
    .asyntai-panel h3{margin:0 0 10px;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#6b6b6b}
    .asyntai-hero h3{font-size:20px;font-weight:700;text-transform:none;letter-spacing:-.02em;color:#111}
    .asyntai-hero p{color:#555;font-size:14px;max-width:94ch}
    .asyntai-fine{color:#757575;font-size:12px;margin-bottom:0}
    .asyntai-btn{display:inline-block;background:#111;color:#fff;border:1px solid #111;padding:10px 20px;font-size:14px;font-weight:600;cursor:pointer;text-decoration:none;line-height:1.4;border-radius:0}
    .asyntai-btn:hover,.asyntai-btn:focus{background:#000;color:#fff;text-decoration:none}
    .asyntai-state{margin:0 0 6px;font-size:15px;font-weight:600;color:#111}
    .asyntai-dot{display:inline-block;width:8px;height:8px;margin-right:9px;vertical-align:middle;background:#1a7f37}
    .asyntai-detail{margin:0 0 4px;color:#555;max-width:68ch}
    .asyntai-links{margin:10px 0 0;display:flex;gap:20px;flex-wrap:wrap}
    .asyntai-links a{color:#555}.asyntai-danger{color:#b32d2e}
    .asyntai-alert{border:1px solid #e0e0e0;border-left-width:3px;padding:10px 14px;margin:0 0 16px;background:#fff}
    .asyntai-check{display:flex;gap:10px;align-items:flex-start;margin:6px 0 16px;color:#333;font-size:14px;cursor:pointer}
    .asyntai-check input{margin-top:3px}
    .asyntai-check small{display:block;color:#757575;font-size:12px;margin-top:2px}
</style>

<div class="asyntai-wrap">
    <div id="asyntai-alert" class="asyntai-alert" style="display:none;"></div>

    <?php if (!$connected): ?>
        <div class="asyntai-panel asyntai-hero">
            <h3>Create an Asyntai account or sign in to enable the chatbot</h3>
            <label class="asyntai-check">
                <input type="checkbox" id="asyntai-share-before" checked>
                <span>Let the chatbot read my published pages directly, so its answers stay up to date.
                    <small>Only pages that any visitor can already see. Nothing on your site is changed.</small></span>
            </label>
            <p><button type="button" id="asyntai-connect" class="asyntai-btn">Get started</button></p>
            <p class="asyntai-fine">A window opens where you sign in or create a free account.</p>
        </div>
    <?php else: ?>
        <div class="asyntai-panel">
            <p class="asyntai-state"><span class="asyntai-dot"></span>Connected<?= $accountEmail !== '' ? ' as ' . e($accountEmail) : '' ?></p>
            <p class="asyntai-detail">The chatbot is on every page of your site. Set it up and read your chat logs in the Asyntai dashboard.</p>
            <p class="asyntai-links">
                <a href="https://asyntai.com/dashboard" target="_blank" rel="noopener">Open your Asyntai dashboard</a>
                <a href="#" id="asyntai-disconnect" class="asyntai-danger">Disconnect this site</a>
            </p>
        </div>

        <div class="asyntai-panel">
            <h3>Your pages</h3>
            <label class="asyntai-check">
                <input type="checkbox" id="asyntai-share" <?= $sharing ? 'checked' : '' ?> data-has-token="<?= $hasToken ? '1' : '0' ?>">
                <span><strong>Share my published pages with the chatbot</strong>
                    <small>Asyntai reads the pages that any visitor can already see, and checks them again for changes. Nothing on your site is changed.</small></span>
            </label>
            <p class="asyntai-detail" id="asyntai-share-status"></p>
        </div>
    <?php endif ?>
</div>

<script>
(function () {
    var strings = {
        preparing: 'Preparing...',
        waiting: 'Waiting for you to finish in the Asyntai window...',
        saving: 'Connected. Saving...',
        blocked: 'Your browser blocked the pop-up. Allow pop-ups and try again.',
        failed: 'Could not connect. Please try again.',
        timeout: 'Timed out waiting for the Asyntai window. Please try again.',
        signedOut: 'October signed you out. Please log in again, then try once more.',
        confirm: 'Disconnect this site from Asyntai? The chatbot is removed from your site.',
        shareOn: 'Page sharing is on.',
        shareOff: 'Page sharing is off. Asyntai can no longer read your pages.',
        reconnect: 'Sign in to Asyntai once more to share your pages.'
    };
    var popup = null;

    function say(message, ok) {
        var box = document.getElementById('asyntai-alert');
        if (!box) { return; }
        box.style.display = 'block';
        box.style.borderLeftColor = ok ? '#1a7f37' : '#b32d2e';
        box.textContent = message;
    }

    function token() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    // Every call is an October AJAX handler on this page: the handler name in
    // the header October reads, and the CSRF token in the one it checks.
    function call(handler, data) {
        var body = new FormData();
        Object.keys(data || {}).forEach(function (key) { body.append(key, data[key]); });

        return fetch(window.location.href, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-OCTOBER-REQUEST-HANDLER': handler,
                'X-CSRF-TOKEN': token()
            },
            body: body
        }).then(function (response) {
            return response.text().then(function (raw) {
                var parsed = null;
                try { parsed = JSON.parse(raw); } catch (e) {}
                if (response.ok && parsed && typeof parsed === 'object') {
                    return parsed;
                }
                if (!response.ok && raw && raw.indexOf('<') !== 0) {
                    throw new Error(raw.trim() || strings.failed);
                }
                var lost = new Error(strings.signedOut);
                lost.signedOut = true;
                throw lost;
            });
        });
    }

    // The window is opened inside the click, before any network call, or the
    // browser blocks it as unrequested.
    function connect(share) {
        popup = window.open('about:blank', 'asyntai_connect', 'width=820,height=740,scrollbars=yes,resizable=yes');
        if (!popup) { say(strings.blocked, false); return; }
        say(strings.preparing, true);

        call('onPrepare', { share: share ? '1' : '0' }).then(function (data) {
            popup.location = data.url;
            say(strings.waiting, true);
            poll(data.state, 0);
        }).catch(function (error) {
            if (popup) { try { popup.close(); } catch (e) {} }
            say(error.message || strings.failed, false);
        });
    }

    function poll(state, attempt) {
        if (attempt > 300) { say(strings.timeout, false); return; }

        call('onPoll', { state: state }).then(function (data) {
            if (data && data.ready && data.site_id) {
                say(strings.saving, true);
                return call('onFinish', {
                    site_id: data.site_id,
                    script_url: data.script_url || '',
                    account_email: data.account_email || ''
                }).then(function () { window.location.reload(); });
            }
            setTimeout(function () { poll(state, attempt + 1); }, 2000);
        }).catch(function (error) {
            if (error && error.signedOut) {
                if (popup) { try { popup.close(); } catch (e) {} }
                say(error.message, false);
                return;
            }
            setTimeout(function () { poll(state, attempt + 1); }, 3000);
        });
    }

    function refreshStatus(tries) {
        var box = document.getElementById('asyntai-share-status');
        var toggle = document.getElementById('asyntai-share');
        if (!box || !toggle) { return; }
        if (!toggle.checked) { box.textContent = ''; return; }

        call('onStatus', {}).then(function (data) {
            var s = data && data.sync;
            if (!s) { box.textContent = 'Asyntai has not read your pages yet.'; return; }
            if (s.status === 'syncing') {
                box.textContent = 'Reading your pages...';
                if (tries < 20) { setTimeout(function () { refreshStatus(tries + 1); }, 5000); }
                return;
            }
            if (s.status === 'failed') { box.textContent = 'The last read failed: ' + (s.error || 'unknown error'); return; }
            box.textContent = 'Pages shared: ' + (s.count || 0) + ' items. Asyntai checks them again for changes.';
        }).catch(function () { box.textContent = ''; });
    }

    function toggleShare(toggle) {
        var on = !!toggle.checked;
        call('onShare', { enabled: on ? '1' : '0' }).then(function (data) {
            if (data && data.needs_connect) {
                toggle.checked = false;
                say(strings.reconnect, true);
                connect(true);
                return;
            }
            say(on ? strings.shareOn : strings.shareOff, true);
            refreshStatus(0);
        }).catch(function (error) {
            toggle.checked = !on;
            say(error.message || strings.failed, false);
        });
    }

    document.addEventListener('click', function (event) {
        var target = event.target;
        if (!target) { return; }
        if (target.id === 'asyntai-connect') {
            event.preventDefault();
            var share = document.getElementById('asyntai-share-before');
            connect(share ? share.checked : false);
            return;
        }
        if (target.id === 'asyntai-disconnect') {
            event.preventDefault();
            if (!window.confirm(strings.confirm)) { return; }
            call('onDisconnect', {}).then(function () {
                window.location.reload();
            }).catch(function (error) {
                say(error.message || strings.failed, false);
            });
        }
    });

    document.addEventListener('change', function (event) {
        if (event.target && event.target.id === 'asyntai-share') {
            toggleShare(event.target);
        }
    });

    refreshStatus(0);
})();
</script>
