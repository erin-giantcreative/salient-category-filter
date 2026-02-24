jQuery(function ($) {
  function buildUrl(baseUrl, catId, pageId) {
    const url = new URL(baseUrl, window.location.origin);

    // Keep any existing query params, but override scf_cat
    if (catId > 0) url.searchParams.set('scf_cat', String(catId));
    else url.searchParams.delete('scf_cat');

    // If filtering a normal Page (not Posts page), pass page ID so PHP knows when to filter
    if (pageId > 0) url.searchParams.set('scf_page_id', String(pageId));
    else url.searchParams.delete('scf_page_id');

    // Bust caches if needed (optional)
    // url.searchParams.set('_scf', String(Date.now()));

    return url.toString();
  }

  function replaceBlogHtml(html, replaceSelector) {
    const $incoming = $('<div>').append($.parseHTML(html));
    const $newBlock = $incoming.find(replaceSelector).first();
    const $oldBlock = $(replaceSelector).first();

    if ($newBlock.length && $oldBlock.length) {
      $oldBlock.replaceWith($newBlock);
      return true;
    }
    return false;
  }

  $(document).on('click', '.scf-blog-filter-ui .scf-btn', function () {
    const $btn = $(this);
    const $ui = $btn.closest('.scf-blog-filter-ui');

    const baseUrl = $ui.data('base-url');
    const replaceSelector = $ui.data('replace-selector');
    const pageId = parseInt($ui.data('page-id'), 10) || 0;

    const catId = parseInt($btn.data('cat'), 10) || 0;

    $ui.find('.scf-btn').removeClass('is-active');
    $btn.addClass('is-active');

    const url = buildUrl(baseUrl, catId, pageId);

    const $target = $(replaceSelector).first();
    $target.addClass('is-loading');

    $.get(url)
      .done(function (html) {
        replaceBlogHtml(html, replaceSelector);
        // Update address bar so the state is shareable
        window.history.replaceState({}, '', url);
      })
      .always(function () {
        $(replaceSelector).first().removeClass('is-loading');
      });
  });

  // Optional: intercept clicks on pagination links inside the replaced block
  // so pagination also stays on-page and keeps the selected category.
  $(document).on('click', '.scf-blog-filter-ui a, .scf-blog-filter-ui button', function(){ /* no-op */ });

  $(document).on('click', 'a', function (e) {
    const $ui = $('.scf-blog-filter-ui').first();
    if (!$ui.length) return;

    const replaceSelector = $ui.data('replace-selector');
    const pageId = parseInt($ui.data('page-id'), 10) || 0;

    // Only intercept clicks inside the blog container
    if (!$(e.target).closest(replaceSelector).length) return;

    const href = $(this).attr('href');
    if (!href) return;

    // Keep the currently selected cat when paginating
    const $active = $ui.find('.scf-btn.is-active').first();
    const catId = parseInt($active.data('cat'), 10) || 0;

    // Only intercept same-origin links
    let url;
    try { url = new URL(href, window.location.origin); } catch (err) { return; }
    if (url.origin !== window.location.origin) return;

    // Apply current filter params onto the pagination URL
    if (catId > 0) url.searchParams.set('scf_cat', String(catId));
    else url.searchParams.delete('scf_cat');

    if (pageId > 0) url.searchParams.set('scf_page_id', String(pageId));
    else url.searchParams.delete('scf_page_id');

    e.preventDefault();

    const $target = $(replaceSelector).first();
    $target.addClass('is-loading');

    $.get(url.toString())
      .done(function (html) {
        replaceBlogHtml(html, replaceSelector);
        window.history.replaceState({}, '', url.toString());
      })
      .always(function () {
        $(replaceSelector).first().removeClass('is-loading');
      });
  });
});