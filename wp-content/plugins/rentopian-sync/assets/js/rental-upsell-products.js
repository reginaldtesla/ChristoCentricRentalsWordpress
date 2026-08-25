jQuery(document).ready(function($) {

    var $carousel = $('.crc-carousel-upsell');
    if ( $carousel.length && typeof $.fn.owlCarousel === 'function' ) {

        $carousel.each(function() {
            var $this = $(this);
            var itemCount = $this.find('.crc-item').length;

            // Prevent double-init (Owl marks container with .owl-loaded or similar)
            if ( !$this.hasClass('owl-loaded') ) {
                $this.owlCarousel({
                    loop: itemCount > 1,
                    margin: 20,        
                    nav: itemCount > 1,
                    // dots: true,
                    smartSpeed: 1000,
                    autoplay: itemCount > 1,
                    autoplayTimeout: 10000,
                    responsive: {
                        0: {
                            items: 1
                        },
                        576: {
                            items: 2
                        },
                        768: {
                            items: 3
                        },
                        1024: {
                            items: 4
                        }
                    },
                    navText: [
                        '<span class="crc-prev">&larr;</span>',
                        '<span class="crc-next">&rarr;</span>'
                    ]
                });
            }
        });

    }

});
