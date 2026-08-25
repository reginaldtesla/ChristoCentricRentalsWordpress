<?php

class RTImage
{
    /**
     * Resolve an array of Rentopian image IDs to WP attachment IDs.
     *
     * For IDs already mapped in the image_relations table the existing WP
     * attachment ID is returned immediately.  Unmapped IDs are downloaded
     * from the Rentopian API, saved as WP attachments, and inserted into
     * the relations table.
     *
     * @param  array $rentalImgIds  Flat array of Rentopian image IDs.
     * @return array  Associative array: [ rental_id => wp_attachment_id, ... ]
     */
    public function getImages(array $rentalImgIds)
    {
        global $wpdb, $rental_tables;
        $rental_image_relations = $wpdb->prefix . $rental_tables["image_relations"];

        $existedImages = [];
        $newImages = [];
        foreach ($rentalImgIds as $rentalImgId) {
            $rentalImgId = (int) $rentalImgId;
            if ($rentalImgId < 1) {
                continue;
            }

            $imgId = $wpdb->get_var(
                $wpdb->prepare("SELECT `id` FROM $rental_image_relations WHERE `rental_id` = %d", $rentalImgId)
            );

            if ($imgId) {
                // Verify the WP attachment still exists (guards against deleted media)
                $attachment_exists = $wpdb->get_var(
                    $wpdb->prepare("SELECT ID FROM `$wpdb->posts` WHERE ID = %d AND post_type = 'attachment'", (int) $imgId)
                );
                if ($attachment_exists) {
                    $existedImages[$rentalImgId] = $imgId;
                } else {
                    // Mapping exists but attachment was deleted — remove stale mapping and re-download
                    $wpdb->delete($rental_image_relations, ['id' => $imgId, 'rental_id' => $rentalImgId]);
                    $newImages[] = $rentalImgId;
                }
            } else {
                $newImages[] = $rentalImgId;
            }
        }

        if ( !empty($newImages)) {
            $downloaded = $this->uploadNewImages($newImages);
            foreach ($downloaded as $rentalImgId => $imgId) {
                $existedImages[$rentalImgId] = $imgId;
            }
        }

        return $existedImages;
    }

    /**
     * Download images from Rentopian and save as WP attachments.
     *
     * @param  array $imgIds  Rentopian image IDs to download.
     * @return array  Associative array: [ rental_id => wp_attachment_id, ... ]
     */
    public function uploadNewImages(array $imgIds)
    {
        global $wpdb, $rental_tables;
        $rental_image_relations = $wpdb->prefix . $rental_tables["image_relations"];

        $images = $this->getImagesFromRentopian($imgIds);
        if (empty($images)) {
            // Log when the API returns nothing so we know it's not a silent swallow
            if (class_exists('ErrorHandler', false) && class_exists('RentalException', false)) {
                ErrorHandler::registerErrorInLog(
                    "Rentopian API returned no images for IDs: " . implode(',', $imgIds),
                    __FILE__, __LINE__,
                    RentalException::TYPE_SYNC_RUNTIME
                );
            }
            return [];
        }

        $existedImages = [];
        $image_relations_sql = [];

        foreach ($images as $image) {
            if ( !$image->url) {
                if (class_exists('ErrorHandler', false) && class_exists('RentalException', false)) {
                    ErrorHandler::registerErrorInLog(
                        "Image rental_id {$image->id} has empty URL — skipping download.",
                        __FILE__, __LINE__,
                        RentalException::TYPE_SYNC_RUNTIME
                    );
                }
                continue;
            }

            // Delegate to centralized downloader (handles mime bypass, perf
            // filters, subsizing, tmp cleanup, and error logging).
            $attach_id = rentopian_webhook_download_image(
                $image->url,
                $image->id,
                $image
            );

            if ( !$attach_id) {
                // Log the failure so it can be diagnosed
                if (class_exists('ErrorHandler', false) && class_exists('RentalException', false)) {
                    ErrorHandler::registerErrorInLog(
                        "Image download/upload failed for rental_id {$image->id}, URL: {$image->url}",
                        __FILE__, __LINE__,
                        RentalException::TYPE_SYNC_RUNTIME
                    );
                }
                continue;
            }

            $existedImages[$image->id] = $attach_id;
            $image_relations_sql[] = "($attach_id, $image->id)";
        }

        if ( !empty($image_relations_sql)) {
            $image_relations_sql = implode(", ", $image_relations_sql);
            $wpdb->query("INSERT INTO `$rental_image_relations` (`id`, `rental_id`) VALUES $image_relations_sql");

            if ($wpdb->last_error !== '') {
                if (class_exists('ErrorHandler', false) && class_exists('RentalException', false)) {
                    ErrorHandler::registerErrorInLog(
                        "SQL error inserting image relations: " . $wpdb->last_error,
                        __FILE__, __LINE__,
                        RentalException::TYPE_SYNC_RUNTIME
                    );
                }
            }
        }

        return $existedImages;
    }

    /**
     * Fetch image data (URLs) from the Rentopian API.
     *
     * @param  array $imgIds  Rentopian image IDs.
     * @return array|mixed  Array of image objects from the API.
     * @throws RentalException  On API failure.
     */
    public function getImagesFromRentopian(array $imgIds)
    {
        $api_key = get_option('rental_api_key');

        try {
            return rental_curl('files/images/stream', $api_key, true, ['images' => json_encode($imgIds)]);
        } catch (RentalException $e) {
            ErrorHandler::registerErrorInLog(
                "API fetch failed in RTImage: " . $e->getMessage(),
                __FILE__, __LINE__,
                $e->getType(),
                null,
                $e->getStatusCode(),
                serialize(['imgIds' => $imgIds])
            );
            throw $e;
        }
    }
}
