jQuery(function ($) {
  function loadPosts($wrap, termId, paged) {
    const postsPerPage = $wrap.data('posts-per-page');
    const taxonomy = $wrap.data('taxonomy');
    const layout = $wrap.data('layout');

    $wrap.addClass('is-loading');

    return $.post(SCF.ajaxUrl, {
      action: 'scf_filter_posts',
      nonce: SCF.nonce,
      term_id: termId,
      taxonomy: taxonomy,
      posts_per_page: postsPerPage,
      layout: layout,
      paged: paged || 1
    })
      .done(function (res) {
        if (res && res.success && res.data && res.data.html !== undefined) {
          $wrap.find('.salient-cat-filter__results').html(res.data.html);
        }
      })
      .always(function () {
        $wrap.removeClass('is-loading');
      });
  }

  $(document).on('click', '.salient-cat-filter .scf-btn', function () {
    const $btn = $(this);
    const $wrap = $btn.closest('.salient-cat-filter');
    const termId = parseInt($btn.data('term-id'), 10) || 0;

    $wrap.find('.scf-btn').removeClass('is-active');
    $btn.addClass('is-active');

    loadPosts($wrap, termId, 1);
  });
});