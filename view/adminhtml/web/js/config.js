requirejs(['jquery', 'mage/translate'], function ($, $t) {
    'use strict';
    $(document).ready(function () {
        const loader = $('#loader');
        const failMessage = $t('Connection did not succeed. Make sure you have entered the right parameters.');
        const successMessage = $t('Connection succeeded');
        const fields = 'groups[fintecture][groups][general][fields]';
        const fintectureEnv = $('select[name="' + fields + '[environment][value]"]').val() ?? '';

        $('input[name="' + fields + '[title][value]"]').attr('disabled', 'disabled');
        $('input[name="' + fields + '[title][value]"]').val($t('Instant bank payment'));

        $(document).on('click', '#connection-test', function (e) {
            e.preventDefault();
            hideMessage();
            const fintectureAppId = $('input[name="' + fields + '[fintecture_app_id_' + fintectureEnv + '][value]"]').val() ?? '';
            const fintectureAppSecret = $('input[name="' + fields + '[fintecture_app_secret_' + fintectureEnv + '][value]"]').val() ?? '';
            const fintecturePrivateKey = $('input[name="' + fields + '[custom_file_upload_' + fintectureEnv + '][value]')?.get(0)?.files[0] ?? '';

            if (fintectureAppId === '' || fintectureAppSecret === '') {
                showMessage(failMessage, false);
                return;
            }

            if (!fintecturePrivateKey) {
                sendAjax(fintectureEnv, fintectureAppId, fintectureAppSecret, '');
            } else {
                let reader = new FileReader();
                reader.addEventListener('load', function (e) {
                    sendAjax(fintectureEnv, fintectureAppId, fintectureAppSecret, e.target.result);
                });
                reader.readAsText(fintecturePrivateKey);
            }
        });

        function showMessage(message, success) {
            loader.hide();
            var connectionTestResult = $('#connection-test-result');
            connectionTestResult.show();
            $('#connection-test-result strong').text(message);
            if (success) {
                connectionTestResult.addClass('message-success');
                connectionTestResult.removeClass('message-error');
            } else {
                connectionTestResult.addClass('message-error');
                connectionTestResult.removeClass('message-success');
            }
        }

        function hideMessage() {
            $('#connection-test-result').hide();
        }

        function sendAjax(fintectureEnv, fintectureAppId, fintectureAppSecret, fintecturePrivateKey) {
            $.ajax({
                showLoader: true,
                url: connectionTestUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    fintectureEnv: fintectureEnv,
                    fintectureAppId: fintectureAppId,
                    fintectureAppSecret: fintectureAppSecret,
                    fintecturePrivateKey: fintecturePrivateKey,
                },
                beforeSend: function () {
                    loader.show();
                },
                success: function (response, textStatus, jqXHR) {
                    if (response instanceof Object) {
                        showMessage(failMessage, false);
                        return;
                    }
                    const message = response ? successMessage : failMessage;
                    const success = response ? true : false;
                    showMessage(message, success);
                },
                error: function () {
                    showMessage(failMessage, false);
                }
            })
        }

        $('select[name="' + fields + '[environment][value]"]').change(function () {
            hideMessage();
        });

        window.ckoToggleSolution = function (id, url) {
            let doScroll = false;
            Fieldset.toggleCollapse(id, url);

            if (this.classList.contains('open')) {
                $('.with-button button.button').each(function (index, otherButton) {
                    if (otherButton !== this && otherButton.classList.contains('open')) {
                        $(otherButton).click();
                        doScroll = true;
                    }
                }
                .bind(this));
            }

            if (doScroll) {
                const pos = Element.cumulativeOffset($(this));
                window.scrollTo(pos[0], pos[1] - 45);
            }
        }
    });
});