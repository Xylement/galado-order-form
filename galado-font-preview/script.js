(function ($) {
    'use strict';

    if (typeof galadoFonts === 'undefined') return;

    $(document).ready(function () {
        var fonts = galadoFonts.fonts;
        var selectedFont = '';

        var $enable    = $('#galado-fp-enable');
        var $fields    = $('#galado-fp-fields');
        var $input     = $('#galado-fp-text');
        var $grid      = $('#galado-fp-grid');
        var $hidden    = $('#galado-fp-selected');
        var $badge     = $('#galado-fp-badge');
        var $badgeName = $('#galado-fp-badge-name');

        // Toggle personalisation fields
        $enable.on('change', function () {
            if (this.checked) {
                $fields.css('display', 'block');
            } else {
                $fields.css('display', 'none');
                // Clear values
                $input.val('');
                $hidden.val('');
                selectedFont = '';
                $badge.css('display', 'none');
                $('input[name="galado_font_color"]').prop('checked', false);
                $('.galado-fp-color-option').removeClass('active');
                $grid.html('<div class="galado-fp-placeholder">Type your text above to see font previews</div>');
            }
        });

        // Font colour selection
        $(document).on('change', 'input[name="galado_font_color"]', function () {
            $('.galado-fp-color-option').removeClass('active');
            $(this).closest('.galado-fp-color-option').addClass('active');
            renderPreviews($input.val());
        });

        function getFontColor() {
            var checked = $('input[name="galado_font_color"]:checked');
            if (!checked.length) return '#1a1a1a';
            return checked.val() === 'White' ? '#aaa' : '#1a1a1a';
        }

        function escapeHtml(str) {
            var div = document.createElement('div');
            div.appendChild(document.createTextNode(str));
            return div.innerHTML;
        }

        function renderPreviews(text) {
            if (!text || !text.trim()) {
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

            // Tap-to-select
            $grid.find('.galado-fp-card').on('click touchend', function (e) {
                e.preventDefault();
                selectedFont = $(this).data('font');
                $hidden.val(selectedFont);
                $badgeName.text(selectedFont);
                $badge.css('display', 'block');
                $grid.find('.galado-fp-card').removeClass('selected');
                $(this).addClass('selected');
            });
        }

        // Live preview on input
        $input.on('input keyup', function () {
            renderPreviews($(this).val());
        });
    });

})(jQuery);
