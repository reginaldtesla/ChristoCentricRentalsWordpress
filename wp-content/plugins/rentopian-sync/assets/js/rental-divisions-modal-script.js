jQuery(document).ready(function($) {
  var $locationModal = $('.rental-location-modal');
  $('.rental-open-location-modal').on('click', function(e) {
    e.preventDefault();
    $locationModal.addClass('opened');
  });
  $('.rental-close-location-modal').on('click', function(e) {
    e.preventDefault();
    $locationModal.removeClass('opened');
  });

  $locationModal.on('click', '.rental-location-select-btn', function(e) {
    e.preventDefault();
    var $this = $(this);
    if ($this.hasClass('rental-selected-location')) {
      return;
    }
    var $form = $('#rental-location-modal-form');
    $form.find('input').val($this.data('id'));
    $form.submit();
  });
});