<?php

    $fp = fopen('/tmp/cloudflare-videos.txt', 'w');

    if(!flock($fp, LOCK_EX | LOCK_NB)) {
        echo 'Unable to obtain lock';
        exit(-1);
    }

    define('CURRENT_FOLDER', '/cron');
    define('RELROOT', '/extensions/cloudflare_videos/cron');
    define('DOCROOT', str_replace(RELROOT, '', rtrim(dirname(__FILE__), '\\/') ));
    define('EXTENSION_ROOT', str_replace(CURRENT_FOLDER, '', rtrim(dirname(__FILE__), '\\/') ));

    require_once(DOCROOT . '/vendor/autoload.php');
    require_once(DOCROOT . '/symphony/lib/boot/bundle.php');

    Administration::instance();

    $config = Symphony::Configuration()->get('cloudflare_videos');

    $tusOptions = array(
        'headers' => array(
            'X-Auth-Email' => $config['email'],
            'X-Auth-Key' => $config['api-key'],
        ),
    );

    $fields = FieldManager::select()
                ->where([
                    'type' => 'cloudflare_video'
                ])
                ->execute()
                ->rows();

    foreach ($fields as $field) {
        $unprocessedVideos = Symphony::Database()
                                ->select()
                                ->from('sym_entries_data_' . $field->get('id'))
                                ->where([
                                    'processed' => 'no'
                                ])
                                ->execute()
                                ->rows();

        foreach ($unprocessedVideos as $unprocessedVideo) {

            if (empty($unprocessedVideo['file'])) {
                continue;
            }

            if ($unprocessedVideo['uploaded'] === 'no') {
                $curl = curl_init();

                curl_setopt_array($curl, array(
                    CURLOPT_URL => 'https://api.cloudflare.com/client/v4/accounts/' . $config['account-id'] . '/media',
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_ENCODING => '',
                    CURLOPT_MAXREDIRS => 10,
                    CURLOPT_TIMEOUT => 0,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                    CURLOPT_CUSTOMREQUEST => 'POST',
                    CURLOPT_POSTFIELDS => array('file'=> new CURLFILE(DOCROOT . '/' . trim($field->get('path'), '/') . '/' . $unprocessedVideo['file'])),
                    CURLOPT_HTTPHEADER => array(
                        'X-Auth-Key: ' . $config['api-key'],
                        'X-Auth-Email: ' . $config['email'],
                    ),
                ));

                $response = curl_exec($curl);
                curl_close($curl);

                $response = json_decode($response, JSON_FORCE_OBJECT);

                if ($response['success']) {
                    $unprocessedVideo['video_url'] = 'https://api.cloudflare.com/client/v4/accounts/' . $config['account-id'] . '/media/' . $response['result']['uid'];
                    $unprocessedVideo['uploaded'] = 'yes';

                    echo 'Done upload for ' . $unprocessedVideo['file'] . PHP_EOL;
                }
            } else {
                echo 'Skipping upload for ' . $unprocessedVideo['file'] . PHP_EOL;
            }

            $entry = (new EntryManager)->select()->entry($unprocessedVideo['entry_id'])->execute()->next();

            if (empty($entry)) {
                continue;
            }

            $section = (new SectionManager)
                            ->select()
                            ->section($entry->get('section_id'))
                            ->execute()
                            ->next();

            if (empty($section)) {
                continue;
            }

            $ch = new Gateway();
            $ch->init($unprocessedVideo['video_url']);
            $ch->setopt('HTTPHEADER', array(
                'X-Auth-Email: ' . $config['email'],
                'X-Auth-Key: ' . $config['api-key'],
            ));

            $cloudflareData = $ch->exec();
            $cloudflareData = json_decode($cloudflareData, JSON_FORCE_OBJECT)['result'];

            if ($cloudflareData['readyToStream'] === true) {
                $unprocessedVideo['processed'] = 'yes';
                echo 'Stream ready for ' . $unprocessedVideo['file'] . PHP_EOL;
            } else {
                echo 'Stream not ready for ' . $unprocessedVideo['file'] . PHP_EOL;
            }

            $unprocessedVideo['meta'] = json_encode($cloudflareData);

            $f = array();

            Symphony::ExtensionManager()->notifyMembers('EntryPreEdit', '/publish/edit/', array(
                'section' => $section,
                'entry' => &$entry,
                'fields' => $f
            ));

            $updateWorked = Symphony::Database()
                        ->update('sym_entries_data_' . $field->get('id'))
                        ->where(['id' => $unprocessedVideo['id']])
                        ->set($unprocessedVideo)
                        ->execute()
                        ->success();

            if ($updateWorked) {
                Symphony::ExtensionManager()->notifyMembers('EntryPostEdit', '/publish/edit/', array(
                    'section' => $section,
                    'entry' => $entry,
                    'fields' => $f
                ));
            }
        }
    }

    fclose($fp);
