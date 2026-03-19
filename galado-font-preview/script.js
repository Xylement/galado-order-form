(function ($) {
    'use strict';

    if (typeof galadoFonts === 'undefined') return;

    var fonts = galadoFonts.fonts;
    var selectedFont = '';

    var $enable   = $('#galado-fp-enable');
    var $fields   = $('#galado-fp-fields');
    var $input    = $('#galado-fp-text');
    var $grid     = $('#galado-fp-grid');
    var $hidden   = $('#galado-fp-selected');
    var $badge    = $('#galado-fp-badge');
    var $badgeName = $('#galado-fp-badge-name');

    // Toggle personalisation fields
    $enable.on('change', function () {
        if (this.checked) {
            $fields.slideDown(250);
        } else {
            $fields.slideUp(200);
            // Clear values when unchecked
            $input.val('');
            $hidden.val('');
            selectedFont = '';
            $badge.hide();
            $('input[name="galado_font_color"]').prop('checked', false);
            $grid.html('<div class="galado-fp-placeholder">Type your text above to see font previews</div>');
        }
    });

    // Get selected font colour for preview styling
    function getFontColor() {
        var checked = $('input[name="galado_font_color"]:checked');
        if (!checked.length) return '#1a1a1a';
        return checked.val() === 'White' ? '#999' : '#1a1a1a';
    }

    // Re-render when colour changes
    $(document).on('change', 'input[name="galado_font_color"]', function () {
        renderPreviews($input.val());
    });

    function renderPreviews(text) {
        if (!text.trim()) {
            $grid.html('<div class="galado-fp-placeholder">Type your text above to see font previews</div>');
            return;
        }

        var color = getFontColor();
        var html = '';
        for (var i = 0; i < fonts.length; i++) {
            var f = fonts[i];
            var sel = selectedFont === f ? ' selected' : '';
            html += '<div class="galado-fp-card' + sel + '" data-font="' + f + '">';
            html += '<span class="font-name">' + f + '</span>';
            html += '<span class="font-sample" style="font-family:\'' + f + '\';color:' + color + ';">' + escapeHtml(text) + '</span>';
            html += '</div>';
        }
        $grid.html(html);

        // Bind tap-to-select
        $grid.find('.galado-fp-card').on('click', function () {
            selectedFont = $(this).data('font');
            $hidden.val(selectedFont);
            $badgeName.text(selectedFont);
            $badge.show();
            $grid.find('.galado-fp-card').removeClass('selected');
            $(this).addClass('selected');
        });
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    // Live preview on input
    $input.on('input', function () {
        renderPreviews($(this).val());
    });

    // Re-render if text already present (e.g. browser back)
    if ($input.val()) {
        renderPreviews($input.val());
    }

})(jQuery);
