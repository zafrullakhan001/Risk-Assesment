<script>
(function () {
    var theme = localStorage.getItem('ra-theme') || 'teal';
    document.documentElement.setAttribute('data-theme', theme);
    document.documentElement.classList.add('is-compact');
})();
</script>
