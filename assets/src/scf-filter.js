jQuery(
	function ($) {

		if (typeof SCF_BLOG_FILTER === 'undefined') {
			console.warn( 'SCF_BLOG_FILTER not defined' );
			return;
		}

		// In-memory cache: key = "catId_pageId", value = html string.
		const htmlCache = new Map();

		function hydrateNectarLazy($scope) {
			// <img class="nectar-lazy" data-nectar-img-src="...">
			$scope.find( 'img.nectar-lazy' ).each(
				function () {
					const $img = $( this );

					const src        = $img.attr( 'src' ) || '';
					const dataSrc    = $img.attr( 'data-nectar-img-src' ) || '';
					const dataSrcset = $img.attr( 'data-nectar-img-srcset' ) || '';
					const dataSizes  = $img.attr( 'data-nectar-img-sizes' ) || '';

					// If Salient hasn't set the real src yet, do it now
					if (dataSrc && ( ! src || src.indexOf( 'data:image' ) === 0)) {
						$img.attr( 'src', dataSrc );
					}

					if (dataSrcset) {
						$img.attr( 'srcset', dataSrcset );
					}
					if (dataSizes) {
						$img.attr( 'sizes', dataSizes );
					}

					// Remove lazy class so it doesn't stay "waiting"
					$img.removeClass( 'nectar-lazy' );
				}
			);

			// Background lazy images (common in Salient)
			$scope.find( '[data-nectar-bg]' ).each(
				function () {
					const el = this;
					const bg = el.getAttribute( 'data-nectar-bg' );
					if (bg) {
						el.style.backgroundImage = 'url("' + bg + '")';
						el.removeAttribute( 'data-nectar-bg' );
					}
				}
			);

			// Kick any layout recalcs Salient might need
			window.dispatchEvent( new Event( 'resize' ) );
		}

		function setUrlState(baseUrl, catId, pageId) {
			try {
				const url = new URL( baseUrl, window.location.origin );

				if (catId > 0) {
					url.searchParams.set( 'scf_cat', String( catId ) );
				} else {
					url.searchParams.delete( 'scf_cat' );
				}

				if (pageId > 0) {
					url.searchParams.set( 'scf_page_id', String( pageId ) );
				} else {
					url.searchParams.delete( 'scf_page_id' );
				}

				window.history.replaceState( {}, '', url.toString() );
			} catch (e) {
				console.warn( 'URL update failed', e );
			}
		}

		/**
		 * Request blog HTML via the REST API endpoint (GET /wp-json/scf/v1/blog-html).
		 * Falls back to the legacy admin-ajax POST if restUrl is not available.
		 * Returns a jQuery Deferred — resolved with { html, cached } shape.
		 */
		function requestBlogHtml(baseUrl, replaceSelector, catId, pageId) {
			const cacheKey = catId + '_' + pageId;

			if (htmlCache.has( cacheKey )) {
				return $.Deferred().resolve( { html: htmlCache.get( cacheKey ), cached: true } ).promise();
			}

			let req;

			if (SCF_BLOG_FILTER.restUrl) {
				req = $.ajax(
					{
						url:      SCF_BLOG_FILTER.restUrl,
						type:     'GET',
						dataType: 'json',
						data: {
							base_url:         baseUrl,
							replace_selector: replaceSelector,
							cat_id:           catId,
							page_id:          pageId
						}
					}
				);
			} else {
				// Legacy fallback for environments where REST API is unavailable
				req = $.ajax(
					{
						url:      SCF_BLOG_FILTER.ajaxUrl,
						type:     'POST',
						dataType: 'json',
						data: {
							action:           'scf_get_blog_html',
							nonce:            SCF_BLOG_FILTER.nonce,
							base_url:         baseUrl,
							replace_selector: replaceSelector,
							cat_id:           catId,
							page_id:          pageId
						}
					}
				);
			}

			// Populate the cache on success so subsequent calls are instant.
			req.done(
				function (res) {
					const html = extractHtml( res );
					if (html !== null) {
						htmlCache.set( cacheKey, html );
					}
				}
			);

			return req;
		}

		function extractHtml(res) {
			if (res && typeof res.html === 'string') {
				return res.html;
			}
			if (res && res.success && res.data && typeof res.data.html === 'string') {
				return res.data.html;
			}
			return null;
		}

		function replaceContainer(replaceSelector, html) {
			const $target = $( replaceSelector ).first();
			if ( ! $target.length) {
				console.warn( 'Replace selector not found:', replaceSelector );
				return false;
			}

			$target.html( html );

			// Re-hydrate Salient lazy images in the replaced content
			hydrateNectarLazy( $target );

			return true;
		}

		// Silently pre-fetch a category and store it in the cache.
		function prefetch(baseUrl, replaceSelector, catId, pageId) {
			const cacheKey = catId + '_' + pageId;
			if (htmlCache.has( cacheKey )) {
				return;
			}
			requestBlogHtml( baseUrl, replaceSelector, catId, pageId );
		}

		$( document ).on(
			'click',
			'.scf-blog-filter-ui .scf-btn',
			function (e) {
				e.preventDefault();

				const $btn = $( this );
				const $ui  = $btn.closest( '.scf-blog-filter-ui' );

				if ($ui.hasClass( 'is-loading' )) {
					return;
				}

				const baseUrl         = $ui.data( 'base-url' );
				const replaceSelector = $ui.data( 'replace-selector' );
				const pageId          = parseInt( $ui.data( 'page-id' ), 10 ) || 0;
				const catId           = parseInt( $btn.data( 'cat' ), 10 ) || 0;

				$ui.find( '.scf-btn' ).removeClass( 'is-active' ).attr( 'aria-pressed', 'false' );
				$btn.addClass( 'is-active' ).attr( 'aria-pressed', 'true' );

				// If the response is already cached, swap instantly without showing the loading state.
				const cacheKey = catId + '_' + pageId;
				if (htmlCache.has( cacheKey )) {
					replaceContainer( replaceSelector, htmlCache.get( cacheKey ) );
					setUrlState( baseUrl, catId, pageId );
					$ui.find( '.scf-live-status' ).text( $btn.text().trim() );
					return;
				}

				$ui.addClass( 'is-loading' );
				const $target = $( replaceSelector ).first();
				$target.addClass( 'is-loading' ).attr( 'aria-busy', 'true' );

				requestBlogHtml( baseUrl, replaceSelector, catId, pageId )
				.done(
					function (res) {
						const html = extractHtml( res );

						if (html !== null) {
							replaceContainer( replaceSelector, html );
							setUrlState( baseUrl, catId, pageId );
							$ui.find( '.scf-live-status' ).text( $btn.text().trim() );
						} else {
							console.warn( 'Unexpected SCF response', res );
						}
					}
				)
				.fail(
					function (xhr, status, error) {
						console.error( 'SCF request failed:', status, error, xhr && xhr.responseText );
					}
				)
				.always(
					function () {
						$ui.removeClass( 'is-loading' );
						$( replaceSelector ).first().removeClass( 'is-loading' ).attr( 'aria-busy', 'false' );
					}
				);
			}
		);

		// Prefetch on hover so the response is often ready before the click lands.
		$( document ).on(
			'mouseenter',
			'.scf-blog-filter-ui .scf-btn',
			function () {
				const $btn = $( this );
				const $ui  = $btn.closest( '.scf-blog-filter-ui' );

				const baseUrl         = $ui.data( 'base-url' );
				const replaceSelector = $ui.data( 'replace-selector' );
				const pageId          = parseInt( $ui.data( 'page-id' ), 10 ) || 0;
				const catId           = parseInt( $btn.data( 'cat' ), 10 ) || 0;

				prefetch( baseUrl, replaceSelector, catId, pageId );
			}
		);

	}
);
