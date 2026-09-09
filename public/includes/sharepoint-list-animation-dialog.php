<dialog class="sp-list-animation-dialog" id="sharepoint-list-animation-dialog" aria-labelledby="sharepoint-list-animation-title">
    <div class="sp-list-animation-shell">
        <header class="sp-list-animation-head">
            <div>
                <span class="sp-list-animation-kicker">File-list appearance</span>
                <h2 id="sharepoint-list-animation-title">✨ Choose an animation</h2>
            </div>
            <button type="button" class="sp-list-animation-close" data-animation-close aria-label="Close animation picker">×</button>
        </header>

        <div class="sp-list-animation-body">
            <label class="sp-list-animation-search">
                <span aria-hidden="true">⌕</span>
                <input type="search" id="sharepoint-list-animation-search" placeholder="Search animations…" autocomplete="off">
            </label>

            <section class="sp-list-animation-preview" aria-label="Animation preview">
                <div>
                    <span class="sp-list-animation-preview-label">Preview</span>
                    <strong id="sharepoint-list-animation-preview-name">Soft Landing</strong>
                </div>
                <div class="sp-list-animation-preview-card" id="sharepoint-list-animation-preview-card">
                    <span aria-hidden="true">📁</span>
                    <span><strong>Sample project</strong><small>12 files · updated today</small></span>
                </div>
            </section>

            <section class="sp-list-animation-speed" aria-label="Animation speed">
                <div class="sp-list-animation-speed-head">
                    <label for="sharepoint-list-animation-speed">Animation speed</label>
                    <output id="sharepoint-list-animation-speed-value" for="sharepoint-list-animation-speed">1× · Normal</output>
                </div>
                <div class="sp-list-animation-speed-control">
                    <span>Slower</span>
                    <input type="range" id="sharepoint-list-animation-speed" min="20" max="200" step="10" value="100">
                    <span>Faster</span>
                </div>
            </section>

            <div class="sp-list-animation-options" id="sharepoint-list-animation-options" role="listbox" aria-label="File-list animations"></div>
            <p class="sp-list-animation-empty" id="sharepoint-list-animation-empty" hidden>No animations match your search.</p>
        </div>

        <footer class="sp-list-animation-footer">
            <span><strong id="sharepoint-list-animation-footer-name">Soft Landing</strong> · saved on this browser</span>
            <div>
                <button type="button" class="button ghost" id="sharepoint-list-animation-replay">Replay</button>
                <button type="button" class="button button-primary" data-animation-close>Done</button>
            </div>
        </footer>
    </div>
</dialog>
