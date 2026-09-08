<script>
(function () {
    var theme = localStorage.getItem('ra-theme') || 'teal';
    var pref = localStorage.getItem('ra-size') || 'auto';
    var navMode = localStorage.getItem('ra-nav') || 'menu';
    var allowed = { auto: 1, s: 1, m: 1, l: 1, xl: 1, xxl: 1 };
    if (!allowed[pref]) {
        pref = 'auto';
    }
    if (navMode !== 'bar') {
        navMode = 'menu';
    }
    function autoSize() {
        var width = window.innerWidth;
        if (width < 1280) {
            return 's';
        }
        if (width < 1540) {
            return 'm';
        }
        if (width < 1800) {
            return 'l';
        }
        if (width < 2200) {
            return 'xl';
        }
        return 'xxl';
    }
    var size = pref === 'auto' ? autoSize() : pref;
    var root = document.documentElement;
    root.setAttribute('data-theme', theme === 'indigo' ? 'indigo' : 'teal');
    root.setAttribute('data-size-pref', pref);
    root.setAttribute('data-size', size);
    root.setAttribute('data-nav-mode', navMode);
    root.classList.toggle('is-compact', size === 's' || size === 'm');
})();
</script>
