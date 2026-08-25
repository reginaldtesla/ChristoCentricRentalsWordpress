(function($){
    $(document).ready(function(){
        // Click on the trigger; adjust selector if your trigger class is different
        $(document).on('click', '.page-open-popup-search', function(e){
            e.preventDefault();
            var $popup = $('#rental-plugin-search-popup');
            $popup.addClass('open').attr('aria-hidden', 'false');
            // Focus the input
            setTimeout(function(){
                $popup.find('.rental-plugin-search-input').focus();
            }, 100);
        });
        // Close on clicking close button or overlay
        $(document).on('click', '#rental-plugin-search-popup .rental-plugin-popup-close, #rental-plugin-search-popup .rental-plugin-popup-overlay', function(e){
            e.preventDefault();
            $('#rental-plugin-search-popup').removeClass('open').attr('aria-hidden', 'true');
        });
        // Close on ESC
        $(document).on('keydown', function(e){
            if ( e.key === "Escape" ) {
                $('#rental-plugin-search-popup').removeClass('open').attr('aria-hidden', 'true');
            }
        });
    });
})(jQuery);
    