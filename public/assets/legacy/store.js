/*
 * Store screen, the "legacy" way: the table is rendered by PHP and this file
 * enhances it with jQuery 3.7. It talks to the same JSON API as the Vue planner.
 */
$(function () {
  'use strict';

  $.ajaxSetup({ headers: { 'X-CSRF-Token': $('meta[name="csrf-token"]').attr('content') } });

  // Inline filter ------------------------------------------------------------
  var $filter = $('#store-filter');

  $filter.on('input', function () {
    var query = $.trim($(this).val()).toLowerCase();

    $('[data-filterable] tbody tr[data-search]').each(function () {
      $(this).toggle($(this).attr('data-search').indexOf(query) !== -1);
    });
    // Hide a category heading when everything under it is filtered out.
    $('#gear tr.group').each(function () {
      var group = $(this).attr('data-group');
      $(this).toggle($('#gear tr[data-in-group="' + group + '"]:visible').length > 0);
    });
  });

  $(document).on('keydown', function (event) {
    if (event.key === '/' && !$(event.target).is('input, textarea, select')) {
      event.preventDefault();
      $filter.trigger('focus').trigger('select');
    }
  });

  // Receive modal ------------------------------------------------------------
  var dialog = document.getElementById('receive-dialog');
  var $form = $('#receive-form');

  function clearErrors() {
    $form.find('[data-error-for]').text('');
    $form.find('[aria-invalid]').removeAttr('aria-invalid');
    $('#receive-error').text('');
  }

  $('#consumables').on('click', 'button[data-receive]', function () {
    var $row = $(this).closest('tr');

    $form[0].reset();
    clearErrors();
    $form.find('[name=consumable_id]').val($row.data('id'));
    $form.find('[name=received_on]').val(new Date().toLocaleDateString('en-CA'));
    $('#receive-title').text('Receive ' + $row.data('name'));
    dialog.showModal();
    $form.find('[name=lot_code]').trigger('focus');
  });

  $('#receive-cancel').on('click', function () {
    dialog.close();
  });

  $form.on('submit', function (event) {
    event.preventDefault();
    clearErrors();
    var $submit = $form.find('[type=submit]').prop('disabled', true);

    $.ajax({
      url: '/api/receipts',
      method: 'POST',
      contentType: 'application/json',
      dataType: 'json',
      data: JSON.stringify({
        consumable_id: $form.find('[name=consumable_id]').val(),
        lot_code: $form.find('[name=lot_code]').val(),
        qty: $form.find('[name=qty]').val(),
        received_on: $form.find('[name=received_on]').val()
      })
    })
      .done(function (data) {
        updateRow(data.consumable);
        dialog.close();
      })
      .fail(function (xhr) {
        var body = xhr.responseJSON || {};
        if (xhr.status === 422 && body.details) {
          $.each(body.details, function (field, message) {
            $form.find('[data-error-for="' + field + '"]').text(message);
            $form.find('[name="' + field + '"]').attr('aria-invalid', 'true');
          });
          $form.find('[aria-invalid]').first().trigger('focus');
        } else {
          $('#receive-error').text(body.error || 'Could not receive this lot. Try again.');
        }
      })
      .always(function () {
        $submit.prop('disabled', false);
      });
  });

  // Update one row in place, no reload.
  function updateRow(item) {
    var $row = $('#consumables tr[data-id="' + item.id + '"]');

    $row.find('[data-col=on_hand]').contents().first().replaceWith(item.on_hand.toLocaleString('en-GB') + ' ');
    $row.find('[data-col=open_lots]').text(item.open_lots);
    $row.find('[data-col=oldest_open_lot]').text(item.oldest_open_lot || '—');
    $row.find('[data-col=status] .badge').text(item.below_reorder ? 'Below reorder' : 'OK');
    $row.toggleClass('is-low', item.below_reorder);

    $row.removeClass('flash');
    void $row[0].offsetWidth; // restart the CSS animation
    $row.addClass('flash');
  }
});
