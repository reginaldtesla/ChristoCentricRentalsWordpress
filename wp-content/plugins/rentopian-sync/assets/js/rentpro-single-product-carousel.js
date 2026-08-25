jQuery(document).ready(function($) {

    var $carousel = $('.rentpro-owl-carousel');
    if ( !$carousel.length || typeof $.fn.owlCarousel !== 'function' ) {
        return;
    }

    $carousel.each(function() {
        var $this = $(this);
        // Prevent double initialization: OwlCarousel adds .owl-loaded class after init
        if ( $this.hasClass('owl-loaded') ) {
            return;
        }

        // Default settings; override via data-attributes or localized settings if needed
        var defaultSettings = {
            items: 1,
            margin: 10,
            loop: false,
            nav: true,
            dots: false,
            autoHeight: true,
            responsive: {
                0:   { items: 1 },
                600: { items: 1 },
                1000:{ items: 1 }
            }
        };

        // If localized settings passed via wp_localize_script:
        if ( typeof window.rentproSingleCarouselSettings === 'object' ) {
            // Merge shallowly:
            $.extend(defaultSettings, window.rentproSingleCarouselSettings);
            // If breakpoints need adjusting, handle here
        }

        // If you prefer reading data-attributes on the wrapper:
        // data-items, data-margin, data-loop, data-nav, data-dots, data-autoheight, etc.
        var dataItems = parseInt($this.data('items'), 10);
        if ( !isNaN(dataItems) ) {
            defaultSettings.items = dataItems;
            defaultSettings.responsive[600].items = dataItems;
            defaultSettings.responsive[1000].items = dataItems;
        }
        var dataMargin = parseInt($this.data('margin'), 10);
        if ( !isNaN(dataMargin) ) {
            defaultSettings.margin = dataMargin;
        }
        if ( $this.data('loop') !== undefined ) {
            defaultSettings.loop = (String($this.data('loop')) === '1' || String($this.data('loop')).toLowerCase() === 'true');
        }
        if ( $this.data('nav') !== undefined ) {
            defaultSettings.nav = (String($this.data('nav')) !== '0' && String($this.data('nav')).toLowerCase() !== 'false');
        }
        if ( $this.data('dots') !== undefined ) {
            defaultSettings.dots = (String($this.data('dots')) !== '0' && String($this.data('dots')).toLowerCase() !== 'false');
        }
        if ( $this.data('autoheight') !== undefined ) {
            defaultSettings.autoHeight = (String($this.data('autoheight')) === '1' || String($this.data('autoheight')).toLowerCase() === 'true');
        }

        // Initialize OwlCarousel
        $this.owlCarousel(defaultSettings);

        // ——— build a thumbnail container ———
        var $thumbs = $('<div class="rentpro-thumbs"></div>').insertAfter($this);
        $this.find('.item').each(function(i){
        var $img = $(this).find('img').first();
        var thumbSrc = $img.attr('data-src') || $img.data('src') || $img.attr('src');
        if ( thumbSrc ) {
            $('<img>')
            .addClass('rentpro-thumb')
            .attr('src', thumbSrc)
            .attr('data-index', i)
            .appendTo($thumbs);
        }
        });
        $thumbs.find('[data-index="0"]').addClass('active');

        // thumb click → main carousel
        $thumbs.on('click', '.rentpro-thumb', function(){
        var idx = $(this).data('index');
        $this.trigger('to.owl.carousel', [ idx, 300 ]);
        });

        // keep active thumb in sync
        $this.on('changed.owl.carousel', function(e){
        var idx = e.item.index;
        $thumbs
            .find('.active').removeClass('active')
            .end()
            .find('[data-index="'+ idx +'"]').addClass('active');
        });


        // When any variation is chosen, Woo fires `found_variation`
        $('.variations_form').on('found_variation', function(ev, variation){
            if ( !variation || !variation.image_id ) {
                return;
            }

            // find the carousel item whose data-image-id matches
            var selector = '.rentpro-owl-carousel .item[data-image-id="' + variation.image_id + '"]';
            var $target = $( selector );
            if ( !$target.length ) {
                return;
            }

            // calculate its zero-based index within the carousel
            var idx = $carousel.find('.item').index( $target );

            // slide there
            $carousel.trigger('to.owl.carousel', [ idx, 300 ]);
        });

        // if swatches plugin doesn’t automatically trigger `change` on the <select>,
        //    you can forward clicks on .isw-term into a select‐change event:
        $('.variations_form').on('click', '.isw-term.term-link', function(e){
            e.preventDefault();
            var term = $(this).data('term');
            var $select = $(this)
            .closest('.variations_form')
            .find('select[name="attribute_pa_color"]');

            if ( $select.length ) {
                $select.val(term).trigger('change');
            }
        });


    });
});
