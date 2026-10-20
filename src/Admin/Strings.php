<?php

declare(strict_types=1);

namespace Fluxis\WooCommerce\Admin;

defined('ABSPATH') || exit;

/**
 * Translated labels shared by PHP views and the admin scripts.
 */
final class Strings
{
    /**
     * @return array<string, string>
     */
    public static function js(): array
    {
        return [
            'start' => __('Start optimization', 'fluxis-for-woocommerce'),
            'cancel' => __('Cancel run', 'fluxis-for-woocommerce'),
            'cancelling' => __('Cancelling…', 'fluxis-for-woocommerce'),
            'starting' => __('Starting…', 'fluxis-for-woocommerce'),
            'close' => __('Close', 'fluxis-for-woocommerce'),
            'reload' => __('Reload', 'fluxis-for-woocommerce'),
            /* translators: 1: finished items, 2: total items */
            'progress' => __('%1$d of %2$d done', 'fluxis-for-woocommerce'),
            /* translators: %d: number of finished items that WooCommerce has received or that needed no change */
            'delivered' => __('%d handled in WooCommerce', 'fluxis-for-woocommerce'),
            /* translators: %d: number of failed, cancelled or reverted items */
            'failedCount' => __('%d failed or cancelled', 'fluxis-for-woocommerce'),
            'colSku' => __('SKU', 'fluxis-for-woocommerce'),
            'colTarget' => __('Field', 'fluxis-for-woocommerce'),
            'colStatus' => __('Status', 'fluxis-for-woocommerce'),
            'colError' => __('Error', 'fluxis-for-woocommerce'),
            'status_queued' => __('Queued', 'fluxis-for-woocommerce'),
            'status_running' => __('Running', 'fluxis-for-woocommerce'),
            'status_completed' => __('Completed', 'fluxis-for-woocommerce'),
            'status_failed' => __('Failed', 'fluxis-for-woocommerce'),
            'status_cancelled' => __('Cancelled', 'fluxis-for-woocommerce'),
            'status_pending' => __('Pending', 'fluxis-for-woocommerce'),
            'status_delivered' => __('Delivered', 'fluxis-for-woocommerce'),
            'status_proposed' => __('Proposed', 'fluxis-for-woocommerce'),
            'status_applied' => __('Applied', 'fluxis-for-woocommerce'),
            'status_skipped' => __('Nothing to change', 'fluxis-for-woocommerce'),
            'status_reverted' => __('Reverted', 'fluxis-for-woocommerce'),
            /* translators: %s: error code */
            'errorGeneric' => __('Something went wrong (%s). Please try again.', 'fluxis-for-woocommerce'),
            'errorNetwork' => __('Could not reach your WordPress site. Check your connection and try again.', 'fluxis-for-woocommerce'),
            'addonTitle' => __('AI Optimizer add-on required in Fluxis', 'fluxis-for-woocommerce'),
            'addonBody' => __('Optimizing texts and images uses the AI Optimizer add-on. Activate it in Fluxis and try again.', 'fluxis-for-woocommerce'),
            'addonLink' => __('Open add-ons in Fluxis', 'fluxis-for-woocommerce'),
            'setupFirst' => __('Finish the Fluxis setup first', 'fluxis-for-woocommerce'),
            'setupTitle' => __('Fluxis setup not finished.', 'fluxis-for-woocommerce'),
            'setupBody' => __('Fluxis is connected, but the setup in Fluxis is not finished yet (connection test, mapping, activation). Finish it in Fluxis, then refresh.', 'fluxis-for-woocommerce'),
            'setupLink' => __('Finish the setup in Fluxis', 'fluxis-for-woocommerce'),
            'budgetTitle' => __('AI budget used up', 'fluxis-for-woocommerce'),
            /* translators: 1: remaining budget, 2: date/time the budget resets */
            'budgetBody' => __('Your Fluxis AI budget for this period is used up (remaining: %1$s). It resets on %2$s.', 'fluxis-for-woocommerce'),
            'unknown' => __('unknown', 'fluxis-for-woocommerce'),
            'validation' => __('Fluxis rejected the request. Check your choices and try again.', 'fluxis-for-woocommerce'),
            'selectTarget' => __('Select at least one field to optimize.', 'fluxis-for-woocommerce'),
            'instructionRequired' => __('Enter an instruction.', 'fluxis-for-woocommerce'),
            'languageInvalid' => __('Enter a two-letter ISO 639-1 language code, for example pt.', 'fluxis-for-woocommerce'),
            'promptRequired' => __('Enter a prompt for the custom edit.', 'fluxis-for-woocommerce'),
            'notConnected' => __('This store is not connected to Fluxis.', 'fluxis-for-woocommerce'),
            'kindUnavailable' => __('This kind of optimization is not available in Fluxis yet.', 'fluxis-for-woocommerce'),
            'importPending' => __('Fluxis is still importing these products. Wait a moment and try again.', 'fluxis-for-woocommerce'),
            'importingImages' => __('Fluxis is importing the product images first…', 'fluxis-for-woocommerce'),
            'importingProducts' => __('Fluxis is importing the products first…', 'fluxis-for-woocommerce'),
            'waitingRateLimited' => __('Fluxis is busy. Trying again in a moment…', 'fluxis-for-woocommerce'),
            'rateLimited' => __('Fluxis is receiving too many requests right now. Wait a moment and try again.', 'fluxis-for-woocommerce'),
            'payloadTooLarge' => __('Fluxis refused the request because it is too large. Select fewer products and try again.', 'fluxis-for-woocommerce'),
            'runTooLarge' => __('The selected products are too large to send to Fluxis in one run (more than 7.5 MB, mostly long descriptions). Select fewer products and try again.', 'fluxis-for-woocommerce'),
            'updatedNotice' => __('Updated by Fluxis — reload to see changes.', 'fluxis-for-woocommerce'),
            'syncQueued' => __('Sync queued. Fluxis receives the product within a minute.', 'fluxis-for-woocommerce'),
            'optimizeWithFluxis' => __('Optimize with Fluxis AI', 'fluxis-for-woocommerce'),
            'optimizeWithFluxisShort' => __('Fluxis AI', 'fluxis-for-woocommerce'),
            'optimizeImageWithFluxis' => __('Optimize image with Fluxis AI', 'fluxis-for-woocommerce'),
            'optimizeImage' => __('Optimize image', 'fluxis-for-woocommerce'),
            'optimizeImages' => __('Optimize images', 'fluxis-for-woocommerce'),
            'optimizeProduct' => __('Optimize product', 'fluxis-for-woocommerce'),
            'optimizeTexts' => __('Optimize texts', 'fluxis-for-woocommerce'),
            'accept' => __('Accept', 'fluxis-for-woocommerce'),
            'acceptAll' => __('Accept all', 'fluxis-for-woocommerce'),
            'accepted' => __('Accepted', 'fluxis-for-woocommerce'),
            'acceptReplace' => __('Accept and replace', 'fluxis-for-woocommerce'),
            'acceptAdd' => __('Accept and add to gallery', 'fluxis-for-woocommerce'),
            'before' => __('Current', 'fluxis-for-woocommerce'),
            'after' => __('Proposal', 'fluxis-for-woocommerce'),
            'generate' => __('Generate', 'fluxis-for-woocommerce'),
            'generating' => __('Fluxis AI is writing…', 'fluxis-for-woocommerce'),
            'generatingImage' => __('Fluxis AI is editing the image…', 'fluxis-for-woocommerce'),
            'saving' => __('Saving…', 'fluxis-for-woocommerce'),
            'instruction' => __('Instruction', 'fluxis-for-woocommerce'),
            'instructionPlaceholder' => __('For example: write in a playful tone and mention free shipping.', 'fluxis-for-woocommerce'),
            'language' => __('Language', 'fluxis-for-woocommerce'),
            'languageOther' => __('Other (ISO code)', 'fluxis-for-woocommerce'),
            'languageOtherLabel' => __('Two-letter ISO 639-1 language code', 'fluxis-for-woocommerce'),
            'languageOtherPlaceholder' => __('e.g. pt', 'fluxis-for-woocommerce'),
            'imageEdit' => __('Edit', 'fluxis-for-woocommerce'),
            'prompt' => __('Prompt', 'fluxis-for-woocommerce'),
            'promptPlaceholder' => __('Describe the edit, e.g. place the product on a marble kitchen counter.', 'fluxis-for-woocommerce'),
            'applyAs' => __('Apply as', 'fluxis-for-woocommerce'),
            'applyReplace' => __('Replace in place', 'fluxis-for-woocommerce'),
            'applyAdd' => __('Add to gallery', 'fluxis-for-woocommerce'),
            'overwrite' => __('Overwrite existing content', 'fluxis-for-woocommerce'),
            'fieldsToOptimize' => __('Fields to optimize', 'fluxis-for-woocommerce'),
            'syncedNote' => __('Fluxis optimizes these fields and writes them back to this product.', 'fluxis-for-woocommerce'),
            'adhocNoSkuNote' => __('This product has no SKU, so the proposals are shown here first and saved when you click Update.', 'fluxis-for-woocommerce'),
            'adhocNote' => __('These fields only exist in WooCommerce: you review the proposals before anything changes.', 'fluxis-for-woocommerce'),
            'moreFieldsLegend' => __('Other text fields of this product', 'fluxis-for-woocommerce'),
            'noMoreFields' => __('This product has no other text fields.', 'fluxis-for-woocommerce'),
            'loadingFields' => __('Loading fields…', 'fluxis-for-woocommerce'),
            'imagesSection' => __('Images', 'fluxis-for-woocommerce'),
            'imagesIntro' => __('Every image used by this product. Select the images to optimize; each result is shown for review before anything changes. The original images are always kept.', 'fluxis-for-woocommerce'),
            'loadingImages' => __('Loading images…', 'fluxis-for-woocommerce'),
            'noImages' => __('This product has no images.', 'fluxis-for-woocommerce'),
            'selectImage' => __('Select image', 'fluxis-for-woocommerce'),
            'syncedSection' => __('Synced fields (via Fluxis)', 'fluxis-for-woocommerce'),
            'syncedRunning' => __('Fluxis is optimizing the fields and writes them back to WooCommerce.', 'fluxis-for-woocommerce'),
            'adhocSection' => __('Proposals', 'fluxis-for-woocommerce'),
            'savedOnUpdate' => __('Saved when you click Update.', 'fluxis-for-woocommerce'),
            'galleryImage' => __('Gallery image', 'fluxis-for-woocommerce'),
            'nothingToDo' => __('Fluxis AI had no proposal for these fields. Try an instruction.', 'fluxis-for-woocommerce'),
            'imageTooLarge' => __('This image is larger than 5 MB, which Fluxis cannot fetch. Use a smaller image.', 'fluxis-for-woocommerce'),
            'imageNotFound' => __('The image no longer exists.', 'fluxis-for-woocommerce'),
            'timeout' => __('Fluxis AI is taking longer than expected. Try again in a moment.', 'fluxis-for-woocommerce'),
            'tooManyFields' => __('Select at most 20 fields.', 'fluxis-for-woocommerce'),
            'attributeValues' => __('Attribute values', 'fluxis-for-woocommerce'),
            'attributeHint' => __('Attribute values separated by " | "; keep the number of values', 'fluxis-for-woocommerce'),
            'delete' => __('Delete', 'fluxis-for-woocommerce'),
            'syncSent' => __('Sent to Fluxis.', 'fluxis-for-woocommerce'),
            'syncFailed' => __('Sync failed:', 'fluxis-for-woocommerce'),
            'syncStillQueued' => __('The sync is still queued; the status updates when it ran.', 'fluxis-for-woocommerce'),
            'altText' => __('Alternative text', 'fluxis-for-woocommerce'),
            'attachmentTitle' => __('Title', 'fluxis-for-woocommerce'),
            'caption' => __('Caption', 'fluxis-for-woocommerce'),
            'attachmentDescription' => __('Description', 'fluxis-for-woocommerce'),
            'modeImprove' => __('Improve', 'fluxis-for-woocommerce'),
            'addInstruction' => __('Add an instruction (optional)', 'fluxis-for-woocommerce'),
            'removeInstruction' => __('Remove the instruction', 'fluxis-for-woocommerce'),
            'withInstruction' => __('With your instruction', 'fluxis-for-woocommerce'),
            'modeFromDataHint' => __('Fluxis AI improves the text based on the product data.', 'fluxis-for-woocommerce'),
            'changeOptions' => __('Change', 'fluxis-for-woocommerce'),
            'shortcutHint' => __('Tip: Ctrl/⌘ + Enter generates.', 'fluxis-for-woocommerce'),
            'tryAgain' => __('Try again', 'fluxis-for-woocommerce'),
            'suggestion' => __('Suggestion', 'fluxis-for-woocommerce'),
            /* translators: %d: number of characters */
            'characters' => __('%d characters', 'fluxis-for-woocommerce'),
            'discard' => __('Discard', 'fluxis-for-woocommerce'),
            'textsSection' => __('Texts', 'fluxis-for-woocommerce'),
            'optionsSection' => __('Options', 'fluxis-for-woocommerce'),
            'addMoreFields' => __('Add more fields', 'fluxis-for-woocommerce'),
            'addImages' => __('Add images', 'fluxis-for-woocommerce'),
            'imagesIntroShort' => __('Optimize the product images too. The originals are always kept.', 'fluxis-for-woocommerce'),
            'cancelDialog' => __('Cancel', 'fluxis-for-woocommerce'),
            /* translators: 1: number of selected text fields, 2: number of selected images */
            'selectionSummary' => __('%1$d texts · %2$d images selected', 'fluxis-for-woocommerce'),
            /* translators: %d: number of selected images */
            'imagesSelected' => __('%d images selected', 'fluxis-for-woocommerce'),
            'resultsTitle' => __('Results', 'fluxis-for-woocommerce'),
            'resultsIntro' => __('Review each result. Nothing changes until you accept it.', 'fluxis-for-woocommerce'),
            'adhocReview' => __('Review the proposals and accept what you like.', 'fluxis-for-woocommerce'),
            'fluxisAi' => __('Fluxis AI', 'fluxis-for-woocommerce'),
            'mediaCardHelp' => __('Improve this image or its texts with AI.', 'fluxis-for-woocommerce'),
            'confirmDisconnect' => __('Disconnect this store from Fluxis? Fluxis can no longer update products until you pair again.', 'fluxis-for-woocommerce'),
        ];
    }

    /**
     * @return array<string, array{label: string, description: string}>
     */
    public static function presets(): array
    {
        return [
            'remove_background' => ['label' => __('Remove background', 'fluxis-for-woocommerce'), 'description' => __('Transparent background, product only.', 'fluxis-for-woocommerce')],
            'white_background' => ['label' => __('White background', 'fluxis-for-woocommerce'), 'description' => __('Clean pure-white studio background.', 'fluxis-for-woocommerce')],
            'grey_background' => ['label' => __('Grey background', 'fluxis-for-woocommerce'), 'description' => __('Neutral light-grey studio background.', 'fluxis-for-woocommerce')],
            'lifestyle' => ['label' => __('Lifestyle', 'fluxis-for-woocommerce'), 'description' => __('Places the product in a realistic setting.', 'fluxis-for-woocommerce')],
            'enhance' => ['label' => __('Enhance', 'fluxis-for-woocommerce'), 'description' => __('Improves light, sharpness and colour.', 'fluxis-for-woocommerce')],
            'square' => ['label' => __('Square', 'fluxis-for-woocommerce'), 'description' => __('Square crop with the product centred.', 'fluxis-for-woocommerce')],
            'remove_text' => ['label' => __('Remove text', 'fluxis-for-woocommerce'), 'description' => __('Removes overlaid text, logos and watermarks.', 'fluxis-for-woocommerce')],
            'catalogue_style' => ['label' => __('Catalogue style', 'fluxis-for-woocommerce'), 'description' => __('Consistent catalogue look: centred, even light, soft shadow.', 'fluxis-for-woocommerce')],
            'custom' => ['label' => __('Custom', 'fluxis-for-woocommerce'), 'description' => __('Describe the edit yourself.', 'fluxis-for-woocommerce')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function textTargets(bool $yoastActive): array
    {
        $targets = [
            'name' => __('Product name', 'fluxis-for-woocommerce'),
            'description' => __('Description', 'fluxis-for-woocommerce'),
            'short_description' => __('Short description', 'fluxis-for-woocommerce'),
        ];

        if ($yoastActive) {
            $targets += [
                'seo_title' => __('Yoast SEO title', 'fluxis-for-woocommerce'),
                'seo_description' => __('Yoast meta description', 'fluxis-for-woocommerce'),
                'seo_focus_keyword' => __('Yoast focus keyphrase', 'fluxis-for-woocommerce'),
            ];
        }

        return $targets;
    }
}
