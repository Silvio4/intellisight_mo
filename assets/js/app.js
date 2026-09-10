(function () {
    'use strict';

    var sidebar = document.getElementById('sidebar');
    var toggle = document.getElementById('sidebarToggle');
    if (sidebar && toggle) {
        var collapsed = localStorage.getItem('intellisight_mo_sidebar') === 'collapsed';
        if (collapsed && window.innerWidth > 680) sidebar.classList.add('collapsed');
        toggle.addEventListener('click', function () {
            if (window.innerWidth <= 680) {
                sidebar.classList.toggle('mobile-open');
                return;
            }
            sidebar.classList.toggle('collapsed');
            localStorage.setItem('intellisight_mo_sidebar', sidebar.classList.contains('collapsed') ? 'collapsed' : 'expanded');
        });
    }

    var zone = document.querySelector('[data-upload-zone]');
    var input = document.querySelector('[data-file-input]');
    var list = document.querySelector('[data-file-list]');
    var countLabel = document.querySelector('[data-file-count]');
    var maxFiles = 10;
    var selected = [];

    function sameFile(a, b) {
        return a.name === b.name && a.size === b.size && a.lastModified === b.lastModified;
    }
    function formatSize(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / 1048576).toFixed(1) + ' MB';
    }
    function syncInput() {
        var transfer = new DataTransfer();
        selected.forEach(function (file) { transfer.items.add(file); });
        input.files = transfer.files;
    }
    function renderFiles() {
        if (!list) return;
        list.innerHTML = '';
        selected.forEach(function (file, index) {
            var row = document.createElement('div');
            row.className = 'file-item';
            var extension = file.name.indexOf('.') > -1 ? file.name.split('.').pop().toUpperCase() : 'FILE';
            row.innerHTML = '<span class="file-type"></span><span class="file-meta"><b></b><small></small></span><button type="button" class="remove-file" aria-label="移除">×</button>';
            row.querySelector('.file-type').textContent = extension.substring(0, 4);
            row.querySelector('b').textContent = file.name;
            row.querySelector('small').textContent = formatSize(file.size);
            row.querySelector('button').addEventListener('click', function () {
                selected.splice(index, 1);
                syncInput();
                renderFiles();
            });
            list.appendChild(row);
        });
        if (countLabel) countLabel.textContent = selected.length + ' / ' + maxFiles;
    }
    function addFiles(files) {
        Array.prototype.forEach.call(files, function (file) {
            if (selected.length >= maxFiles) return;
            if (!selected.some(function (item) { return sameFile(item, file); })) selected.push(file);
        });
        syncInput();
        renderFiles();
    }
    if (zone && input && list) {
        zone.addEventListener('click', function (event) {
            if (!event.target.closest('.remove-file')) input.click();
        });
        input.addEventListener('change', function () { addFiles(input.files); });
        ['dragenter', 'dragover'].forEach(function (name) {
            zone.addEventListener(name, function (event) { event.preventDefault(); zone.classList.add('dragover'); });
        });
        ['dragleave', 'drop'].forEach(function (name) {
            zone.addEventListener(name, function (event) { event.preventDefault(); zone.classList.remove('dragover'); });
        });
        zone.addEventListener('drop', function (event) { addFiles(event.dataTransfer.files); });
    }

    var form = document.querySelector('[data-task-form]');
    if (form) {
        form.addEventListener('submit', function (event) {
            if (!input || input.files.length < 1 || input.files.length > maxFiles) {
                event.preventDefault();
                alert('请上传 1 至 10 份文件。');
                return;
            }
            var button = form.querySelector('[type="submit"]');
            if (button) {
                button.disabled = true;
                button.textContent = '正在上传及转换…';
            }
        });
    }
})();
