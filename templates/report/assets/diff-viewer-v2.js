/* ============================================================
   diff-viewer-v2.js  —  Laravel Upgrader HTML Diff Viewer v2
   Vanilla JS. No frameworks. No external dependencies.
   Self-contained: inlined into the report HTML by the generator.
   ============================================================ */

(function () {
  'use strict';

  // ---- State ----
  var activeHop = null;        // Current active hop key
  var signoffKey = null;       // localStorage namespace key (set from chainId)
  var activeTypeFilters = {};  // { auto: true, review: false, ... }
  var activeConfidenceFilters = {}; // { high: true, medium: true, low: true }
  var filterExt = '';
  var filterDir = '';
  var filterHop = '';          // '' means all hops

  // ---- Init ----
  document.addEventListener('DOMContentLoaded', function () {
    var chainId = document.documentElement.getAttribute('data-chain-id') || 'unknown';
    signoffKey = 'upgrader-signoff-' + chainId;

    initTabs();
    initFilters();
    initFileTree();
    initSignoffs();
    initReviewNotes();
    renderAllDiffs();
  });

  // ---- Tab switching ----
  function initTabs() {
    var tabs    = document.querySelectorAll('.hop-tab');
    var sections = document.querySelectorAll('.hop-section');

    if (tabs.length === 0) { return; }

    // Activate the first tab by default
    activateTab(tabs[0].getAttribute('data-hop'));

    tabs.forEach(function (tab) {
      tab.addEventListener('click', function () {
        activateTab(tab.getAttribute('data-hop'));
      });
      tab.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          activateTab(tab.getAttribute('data-hop'));
        }
      });
    });

    function activateTab(hopKey) {
      activeHop = hopKey;

      tabs.forEach(function (t) {
        var active = t.getAttribute('data-hop') === hopKey;
        t.classList.toggle('active', active);
        t.setAttribute('aria-selected', active ? 'true' : 'false');
      });

      sections.forEach(function (s) {
        if (hopKey === '__all__') {
          s.classList.add('visible');
        } else {
          var active = s.getAttribute('data-hop') === hopKey;
          s.classList.toggle('visible', active);
        }
      });

      updateFilterCount();
    }
  }

  // ---- Filter controls ----
  function initFilters() {
    var filterBar = document.getElementById('filter-bar');
    if (!filterBar) { return; }

    // Change-type buttons
    var typeBtns = filterBar.querySelectorAll('.filter-btn[data-type]');
    typeBtns.forEach(function (btn) {
      var type = btn.getAttribute('data-type');
      activeTypeFilters[type] = true;  // start with all active
      btn.classList.add('active');

      btn.addEventListener('click', function () {
        activeTypeFilters[type] = !activeTypeFilters[type];
        btn.classList.toggle('active', activeTypeFilters[type]);
        applyFilters();
      });
    });

    // Confidence-level buttons
    var confidenceBtns = filterBar.querySelectorAll('.confidence-btn[data-confidence]');
    confidenceBtns.forEach(function (btn) {
      var level = btn.getAttribute('data-confidence');
      activeConfidenceFilters[level] = true;
      btn.classList.add('active');

      btn.addEventListener('click', function () {
        activeConfidenceFilters[level] = !activeConfidenceFilters[level];
        btn.classList.toggle('active', activeConfidenceFilters[level]);
        applyFilters();
      });
    });

    // Hop-number filter
    var hopInput = document.getElementById('filter-hop-input');
    if (hopInput) {
      hopInput.addEventListener('change', function () {
        filterHop = hopInput.value;
        applyFilters();
      });
    }

    // Extension filter
    var extInput = document.getElementById('filter-ext-input');
    if (extInput) {
      extInput.addEventListener('input', function () {
        filterExt = extInput.value.trim().replace(/^\./, '');
        applyFilters();
      });
    }

    // Directory filter
    var dirInput = document.getElementById('filter-dir-input');
    if (dirInput) {
      dirInput.addEventListener('input', function () {
        filterDir = dirInput.value.trim();
        applyFilters();
      });
    }

    // Clear all
    var clearBtn = document.getElementById('filter-clear');
    if (clearBtn) {
      clearBtn.addEventListener('click', function () {
        // Reset type filters
        typeBtns.forEach(function (btn) {
          var type = btn.getAttribute('data-type');
          activeTypeFilters[type] = true;
          btn.classList.add('active');
        });
        // Reset confidence filters
        confidenceBtns.forEach(function (btn) {
          var level = btn.getAttribute('data-confidence');
          activeConfidenceFilters[level] = true;
          btn.classList.add('active');
        });
        filterExt = '';
        filterDir = '';
        filterHop = '';
        if (extInput) { extInput.value = ''; }
        if (dirInput) { dirInput.value = ''; }
        if (hopInput) { hopInput.value = ''; }
        applyFilters();
      });
    }
  }

  function applyFilters() {
    var blocks = document.querySelectorAll('.file-diff-block');
    var visible = 0;

    blocks.forEach(function (block) {
      var changeType  = block.getAttribute('data-change-type') || 'auto';
      var confidence  = block.getAttribute('data-confidence') || 'high';
      var ext         = block.getAttribute('data-ext') || '';
      var dir         = block.getAttribute('data-dir') || '';

      // Hop filter: check which hop section this block belongs to
      var section = block.closest('.hop-section');
      var blockHop = section ? (section.getAttribute('data-hop') || '') : '';

      var showType       = !!activeTypeFilters[changeType];
      var showConfidence = !!activeConfidenceFilters[confidence];
      var showExt        = filterExt === '' || ext.toLowerCase() === filterExt.toLowerCase();
      var showDir        = filterDir === '' || dir.toLowerCase().indexOf(filterDir.toLowerCase()) !== -1;
      var showHop        = filterHop === '' || blockHop === filterHop;
      var show = showType && showConfidence && showExt && showDir && showHop;

      block.classList.toggle('hidden', !show);
      if (show) { visible++; }
    });

    // Also filter sidebar file entries
    var fileEntries = document.querySelectorAll('.file-entry');
    fileEntries.forEach(function (entry) {
      var changeType = entry.getAttribute('data-change-type') || 'auto';
      var file       = entry.getAttribute('data-file') || '';
      var ext        = file.split('.').pop() || '';
      var dir        = file.split('/').slice(0, -1).join('/');

      var showType       = !!activeTypeFilters[changeType];
      var showExt        = filterExt === '' || ext.toLowerCase() === filterExt.toLowerCase();
      var showDir        = filterDir === '' || dir.toLowerCase().indexOf(filterDir.toLowerCase()) !== -1;
      entry.style.display = (showType && showExt && showDir) ? '' : 'none';
    });

    updateFilterCount(visible);
  }

  function updateFilterCount(count) {
    var counter = document.getElementById('filter-count');
    if (!counter) { return; }

    if (count === undefined) {
      var selector = activeHop === '__all__'
        ? '.hop-section.visible .file-diff-block:not(.hidden)'
        : '.hop-section.visible .file-diff-block:not(.hidden)';
      var visible = document.querySelectorAll(selector).length;
      count = visible;
    }
    counter.textContent = count + ' file(s) shown';
  }

  // ---- File tree navigation ----
  function initFileTree() {
    var fileTree = document.getElementById('file-tree');
    if (!fileTree) { return; }

    // Search/highlight input
    var searchInput = document.getElementById('sidebar-search');
    if (searchInput) {
      searchInput.addEventListener('input', function () {
        var query = searchInput.value.trim().toLowerCase();
        var entries = fileTree.querySelectorAll('.file-entry');
        entries.forEach(function (entry) {
          var name = (entry.getAttribute('data-file') || '').toLowerCase();
          entry.style.display = (query === '' || name.indexOf(query) !== -1) ? '' : 'none';
        });
      });
    }

    // Click to scroll to diff
    fileTree.addEventListener('click', function (e) {
      var entry = e.target.closest('.file-entry');
      if (!entry) { return; }
      var file = entry.getAttribute('data-file');
      scrollToDiff(file, entry);
    });

    fileTree.addEventListener('keydown', function (e) {
      if (e.key !== 'Enter' && e.key !== ' ') { return; }
      var entry = e.target.closest('.file-entry');
      if (!entry) { return; }
      e.preventDefault();
      var file = entry.getAttribute('data-file');
      scrollToDiff(file, entry);
    });
  }

  function scrollToDiff(file, activeEntry) {
    if (!file) { return; }

    // Mark active in sidebar
    document.querySelectorAll('.file-entry').forEach(function (e) {
      e.classList.remove('active');
    });
    if (activeEntry) { activeEntry.classList.add('active'); }

    // Find the diff block and scroll
    var encoded = CSS.escape('diff-' + file);
    var block = document.getElementById('diff-' + file);
    if (block) {
      // Make sure the correct hop is visible
      var section = block.closest('.hop-section');
      if (section) {
        var hopKey = section.getAttribute('data-hop');
        if (hopKey && hopKey !== activeHop) {
          var tab = document.querySelector('.hop-tab[data-hop="' + hopKey + '"]');
          if (tab) { tab.click(); }
        }
      }
      block.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
  }

  // ---- Sign-off state (localStorage) ----
  function initSignoffs() {
    var checkboxes = document.querySelectorAll('.signoff-checkbox');

    // Restore persisted state
    checkboxes.forEach(function (cb) {
      var file  = cb.getAttribute('data-file');
      var key   = signoffKey + ':' + file;
      try {
        var saved = localStorage.getItem(key);
        if (saved === '1') {
          cb.checked = true;
          markSignedOff(cb);
        }
      } catch (e) { /* localStorage may be unavailable */ }

      // Persist on change
      cb.addEventListener('change', function () {
        try {
          if (cb.checked) {
            localStorage.setItem(key, '1');
          } else {
            localStorage.removeItem(key);
          }
        } catch (e) { /* ignore */ }
        markSignedOff(cb);
      });
    });
  }

  function markSignedOff(checkbox) {
    var block = checkbox.closest('.file-diff-block');
    if (block) {
      block.classList.toggle('signed-off', checkbox.checked);
    }
  }

  // ---- Review Notes (JSON sidecar) ----
  function initReviewNotes() {
    var notesKey = signoffKey + ':notes';

    // Toggle visibility
    document.addEventListener('click', function (e) {
      var toggle = e.target.closest('.review-note-toggle');
      if (!toggle) { return; }
      var container = toggle.closest('.review-note-container');
      if (!container) { return; }
      var editor = container.querySelector('.review-note-editor');
      if (editor) { editor.classList.toggle('hidden'); }
    });

    // Restore saved notes
    var containers = document.querySelectorAll('.review-note-container');
    var savedNotes = {};
    try {
      var raw = localStorage.getItem(notesKey);
      if (raw) { savedNotes = JSON.parse(raw); }
    } catch (e) { /* ignore */ }

    containers.forEach(function (container) {
      var file = container.getAttribute('data-file');
      var textarea = container.querySelector('.review-note-text');
      if (!textarea || !file) { return; }

      if (savedNotes[file]) {
        textarea.value = savedNotes[file];
        container.querySelector('.review-note-editor').classList.remove('hidden');
      }

      textarea.addEventListener('input', function () {
        try {
          var all = {};
          var raw = localStorage.getItem(notesKey);
          if (raw) { all = JSON.parse(raw); }
          if (textarea.value.trim() === '') {
            delete all[file];
          } else {
            all[file] = textarea.value;
          }
          localStorage.setItem(notesKey, JSON.stringify(all));
        } catch (e) { /* ignore */ }
      });
    });

    // Export notes as JSON download
    var exportBtn = document.getElementById('export-notes-btn');
    if (exportBtn) {
      exportBtn.addEventListener('click', function () {
        try {
          var raw = localStorage.getItem(notesKey);
          var notes = raw ? JSON.parse(raw) : {};
          var chainId = document.documentElement.getAttribute('data-chain-id') || 'unknown';
          var sidecar = {
            chainId: chainId,
            exportedAt: new Date().toISOString(),
            notes: notes
          };
          var blob = new Blob([JSON.stringify(sidecar, null, 2)], { type: 'application/json' });
          var url = URL.createObjectURL(blob);
          var a = document.createElement('a');
          a.href = url;
          a.download = 'review-notes-' + chainId + '.json';
          document.body.appendChild(a);
          a.click();
          document.body.removeChild(a);
          URL.revokeObjectURL(url);
        } catch (e) {
          console.error('Failed to export notes:', e);
        }
      });
    }

    // Import notes from JSON file
    var importBtn = document.getElementById('import-notes-btn');
    var importInput = document.getElementById('import-notes-input');
    if (importBtn && importInput) {
      importBtn.addEventListener('click', function () { importInput.click(); });
      importInput.addEventListener('change', function () {
        var file = importInput.files[0];
        if (!file) { return; }
        var reader = new FileReader();
        reader.onload = function (e) {
          try {
            var sidecar = JSON.parse(e.target.result);
            var notes = sidecar.notes || {};
            localStorage.setItem(notesKey, JSON.stringify(notes));
            // Populate textareas
            containers.forEach(function (container) {
              var f = container.getAttribute('data-file');
              var textarea = container.querySelector('.review-note-text');
              if (!textarea || !f) { return; }
              if (notes[f]) {
                textarea.value = notes[f];
                container.querySelector('.review-note-editor').classList.remove('hidden');
              } else {
                textarea.value = '';
              }
            });
          } catch (err) {
            console.error('Failed to import notes:', err);
          }
        };
        reader.readAsText(file);
        importInput.value = '';
      });
    }
  }

  // ---- Diff2Html rendering ----
  function renderAllDiffs() {
    if (typeof Diff2Html === 'undefined') {
      console.warn('Diff2Html not loaded; diffs will not be rendered.');
      return;
    }

    var blocks = document.querySelectorAll('.file-diff-block');
    blocks.forEach(function (block) {
      var pre     = block.querySelector('.unified-diff');
      var wrapper = block.querySelector('.d2h-wrapper');
      if (!pre || !wrapper) { return; }

      var diffStr = pre.getAttribute('data-diff') || '';
      if (diffStr === '') {
        wrapper.innerHTML = '<p class="no-diff">No diff available.</p>';
        return;
      }

      try {
        var html = Diff2Html.html(diffStr, {
          drawFileList: false,
          matching:     'lines',
          outputFormat: 'side-by-side',
          highlight:    true,
        });
        wrapper.innerHTML = html;
      } catch (e) {
        wrapper.innerHTML = '<p class="no-diff">Could not render diff: ' + escHtml(String(e)) + '</p>';
      }
    });

    updateFilterCount();
  }

  // ---- Utilities ----
  function escHtml(str) {
    return str
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

}());
