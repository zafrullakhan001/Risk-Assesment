<script>
(function () {
    try {
        var path = String(location.pathname || '').replace(/\\/g, '/');
        var file = (path.split('/').pop() || '').toLowerCase();
        var isDirLaunch = file === '' || file === 'public' || !/\.php$/i.test(file);
        if (!isDirLaunch || location.search || (location.hash && location.hash !== '#')) {
            return;
        }
        var stored = JSON.parse(localStorage.getItem('riskregister_last_nav') || '{}');
        if (!stored || typeof stored !== 'object') {
            return;
        }
        var resume = String(stored.last || '').trim();
        if (!resume) {
            var keys = ['catalogs', 'sharepoint', 'owners', 'storage', 'ticket', 'admin', 'upload', 'templates'];
            for (var i = 0; i < keys.length; i += 1) {
                if (stored[keys[i]]) {
                    resume = String(stored[keys[i]]);
                    break;
                }
            }
        }
        if (!resume || resume.length > 2000) {
            return;
        }
        var lower = resume.toLowerCase();
        if (lower.indexOf('login.php') !== -1 || lower.indexOf('logout.php') !== -1) {
            return;
        }
        if (lower.indexOf('index.php') !== -1 && lower.indexOf('sharepoint.php') === -1) {
            return;
        }
        if (resume === path || resume === path + '/' || resume === path + location.search + location.hash) {
            return;
        }
        location.replace(resume);
    } catch (error) {
        /* ignore */
    }
})();
</script>
