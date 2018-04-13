<?php
    require 'aws-autoloader.php';
	//require 'include/config.php';
	
	use Aws\S3\S3Client;
    use Aws\Rekognition\RekognitionClient;
	use Aws\Rekognition\Exception\RekognitionException;
	
	// Load config
	$configFile = './include/config.php';
	if (!file_exists($configFile)) {
	  die('config.php is missing.');
	}else{
		include($configFile);
		define("access_key", $access_key);
		define("secret_key", $secret_key);
	}
	
	if (getenv('access_key') == false || getenv('secret_key') == false || getenv('S3Bucket') == false) {
	  die('Please set bucket-name, access-key, and secret-key in'.' "include/config.php"');
	}
	
	if(isset($_FILES["fileToUpload"])){
		// variables specific to this script
		$localUploadDir = "tmp/";
		$fileName = basename($_FILES["fileToUpload"]["name"]);
		$key_name = explode('.',$fileName);
		
		$targetFile = $localUploadDir . $fileName;
		$uploadOk = 1;
		$imageFileType = strtolower(pathinfo($targetFile,PATHINFO_EXTENSION));

		// see if the image file actualy an image
		if(isset($_POST["submit"])) {
			$check = getimagesize($_FILES["fileToUpload"]["tmp_name"]);
			if($check !== false) {
				$uploadOk = 1;
			} else {
				echo "The file is not an image.";
				$uploadOk = 0;
			}
		}
		
		// check to see if file already exists 
		/* if (file_exists($targetFile)) {
			echo "Sorry, the file already exists.";
			$uploadOk = 0;
		} */

		// make sure the uploaded file is within the size limit
		if ($_FILES["fileToUpload"]["size"] > $uploadSizeLimit) {
			echo "Sorry, the file you uploaded exceeds the maximum allowed size of $uploadSizeLimit bytes.";
			$uploadOk = 0;
		}

		// only allow specific file formats
		if($imageFileType != "jpg" && $imageFileType != "png" && $imageFileType != "jpeg" && $imageFileType != "gif" ) {
			echo "We only allow JPG, JPEG, PNG & GIF files to be uploaded.";
			$uploadOk = 0;
		}
		
		try{
			$options = array(
				'credentials'=>array('key' => access_key,
				'secret' => secret_key),
				'region' => 'eu-west-1',
				'version' => '2016-06-27',
			);
		} catch (S3Exception $e) {
            echo "Oups, this failed. Have You configured Security Crentials?\n\n";
            echo $e->getMessage() . "\n";

            exit(1);
        }
		
		// see if the $uploadOk variable has been set to 0 by an error
	if ($uploadOk == 0) {
		echo "<p>retry your upload.</p>";
		exit();
	// no error was encountered, try to upload file
	} else {
		if (move_uploaded_file($_FILES["fileToUpload"]["tmp_name"], $targetFile)) {
		
		// upload success. before we do anything else, let's make the image smaller and more manageable.
		$scaledFile = "scaled_" . $fileName;
		$resizeCmd = "convert $targetFile -resize 1024x1024\> tmp/" . $scaledFile;
			exec($resizeCmd, $output, $return);
		$rekognition = new RekognitionClient($options);
		
		#Get local image
		$fp_image = fopen($targetFile, 'r');
		$image = fread($fp_image, filesize($targetFile));
		fclose($fp_image);
		
		$keyname = $fileName;
		$imgcollection = $key_name[0];
		
		initFaceCollection($imgcollection);
		//if (detectFaces($S3Bucket,$keyname) > 0){
			compareFaces($keyname,$S3Bucket,$imgcollection,$image);exit;
		//}
		
		# Call DetectFaces
		$labels = $rekognition->detectLabels([
			'Image'		=> [
				'S3Object'	=> [
					'Bucket'	=> $S3Bucket,
					//'Name'		=> $keyname,
				],
			],
		]);
		
		// convert and store just the labels so we can store them in DynamoDB
		$json = json_encode($labels['Labels']);
		// also get the current time to allow some rudimentary sorting later
		$date = date("Ymd");
		
		// now we output the labels that Rekognition returned in a basic HTML table
		echo '<p><table border="1"><tr><th>Label</th><th>Confidence</th></tr>';	
		$i = 0;
		foreach($labels['Labels'] as $row) {
			echo '<tr>';
			echo '<td>' . $row['Name'] . '</td>';
			echo '<td>' . round($row['Confidence'], 2) . '%</td>';
			echo '</tr>';

			// send each label over 85% to an array for later
			if(round($row['Confidence'], 2) >= '85') {
				if($i == 0) {
					// declare the array on first occurrance but not again
					$confidentLabels = array();
				}
				array_push($confidentLabels, $row['Name'] . ': ' . round($row['Confidence'], 2) . '\%');
				$i++;
			}
		}
		echo '</table></p>';

		// check to see if we didn't get any labels over 85% and display a message to that effect on the image
		if(!isset($confidentLabels)) {
			$confidentLabelsCmd = 'Rekognition did not have 85\% or higher confidence in any label for this image.';
		} else {
			// get the labels from the array and put them into a string for image processing
				$confidentLabelsCmd = implode(" | ", $confidentLabels);
		}

		// we're currently storing the number of uploads in a local file
			// instead of DynamoDB due to the inability to easily "count rows".
			// it's time to increment that value.
			$file = 'tmp/count.txt';
			$value = file_get_contents($file);
			$value = $value + 1;
			file_put_contents($file, $value);

		// get the label string we retrieved and annotate the local scaled image
		$labelledFile = 'lbl_' . $fileName;
		$convert = "convert tmp/$scaledFile -pointsize 14 -gravity North -background Plum -splice 0x20 -annotate +0+4 '$confidentLabelsCmd' tmp/$labelledFile";
		exec($convert, $output, $return);
		
		// now we display the final tagged image from S3.
		$filepath = 'tmp/' . $labelledFile;
		$url = $filepath;
		echo '<img src="' . $url . '" />';
		
		// Generate a link to tweet the image
		$tweetURL = 'share?text=AWS Rekognition is pretty awesome, see what it found in my image:&url=' . $url;
		$tweetURL = str_replace(" ", "+", $tweetURL);


		echo '<h3>Share on Twitter?</h3>';
		echo '<p>Now that you have had your image labelled by Rekognition, why not share it with your followers? Click <a href="http://twitter.com/' . $tweetURL . '">here</a> to compose a tweet for this image.</p>';
		} else {
			echo "Sorry, there was an error uploading your file.";
		}
		}	
	}
	
	/**
     * Check if face collection already exists
     * If not, it creates a collection of face signatures
     * @return void
     */
    function initFaceCollection($faceCollectionId)
    {	
        try {
			
			$options = array(
				'credentials'=>array('key' => access_key,
				'secret' => secret_key),
				'region' => 'eu-west-1',
				'version' => '2016-06-27',
			);
			
			$rekognition = new RekognitionClient($options);
			
            $response = $rekognition->listCollections();
			
            echoVerbose($response);
            if ($response['@metadata']['statusCode'] == '200'){
                $collectionIds = $response['CollectionIds'];
                if (!in_array($faceCollectionId, $collectionIds)){
                    echo sprintf("Creating face collection with id: %s \n", $faceCollectionId);
                    $response = $rekognition->createCollection(['CollectionId' => $faceCollectionId]);
                    echoVerbose($response);
                }
            }
         } catch (RekognitionException $e) {
            echoVerbose( $e->getMessage() );
            exit(1);
        }
    }
	
	/**
     * Calls Rekognition detectFaces to output face locations
     * Echos faces with boundingbox, landmarks and confidence score
     * @param string $key key to imagefile in S3 bucket
     * @return int number of detected faces in image
     */
    function detectFaces($S3Bucket,$key)
    {
        try {
            echo sprintf("\n\n===== Detecting faces in Image %s =====\n", $key);
			
			$options = array(
				'credentials'=>array('key' => access_key,
				'secret' => secret_key),
				'region' => 'eu-west-1',
				'version' => '2016-06-27',
			);
			
			$rekognition = new RekognitionClient($options);
			
            $response = $rekognition->detectFaces([
                'Image' => [
                    'S3Object'	=> [
                        'Bucket' => $S3Bucket,
                        'Name' => $key,
                    ],
                ],
            ]);
			
            echoVerbose($response);
        
            $faceCount = empty($response['FaceDetails']) ? 0 : count($response['FaceDetails']);
			
            if ($faceCount > 0){
                echo sprintf("Found %d faces, here are the bounding boxes\n", $faceCount);
                foreach($response['FaceDetails'] as $face){
                    print_r($face['BoundingBox'])."\n";
                }
            } else {
                echo sprintf("No faces detected\n");
            }

            return $faceCount;

        } catch (RekognitionException $e) {
            echoVerbose( $e->getMessage() );
            exit(1);
        }
    }
	
	/**
     * Calls Rekognition searchFacesByImage to search image for faces
     * that matches signtures in collection
     * Echos file keys with matching faces
     * @param string $key key to imagefile in S3 bucket
     * @return array keys of images with matching faces
     */
    function searchFaces($key, $S3Bucket, $faceCollectionId, $bytefile)
    {
		
        echo sprintf("\n\nSearching for matching faces in image %s \n", $key);
        $matchingKeys = [];
		$verbose = false; // echo api response from AWA
		
		$options = array(
				'credentials'=>array('key' => access_key,
				'secret' => secret_key),
				'region' => 'eu-west-1',
				'version' => '2016-06-27',
			);
			
			$rekognition = new RekognitionClient($options);
			
            $response = $rekognition->searchFacesByImage([
                'CollectionId' => $faceCollectionId,
                'Image' => [
					'S3Object'	=> [
                        'Bucket' => $S3Bucket,
                        'Name' => $key,
                    ],
                ],
                'MaxFaces' => 5
            ]);
			echo "<pre>";print_r($response);exit;
		
        try {
			$options = array(
				'credentials'=>array('key' => access_key,
				'secret' => secret_key),
				'region' => 'eu-west-1',
				'version' => '2016-06-27',
			);
			
			$rekognition = new RekognitionClient($options);
			
            $response = $rekognition->searchFacesByImage([
                'CollectionId' => $faceCollectionId,
                'Image' => [
					'Bytes'=> $bytefile,
                    'S3Object'	=> [
                        'Bucket' => $S3Bucket,
                        'Name' => $key,
                    ],
                ],
                'MaxFaces' => 5
            ]);
			echo "<pre>";print_r($response);exit;
			echoVerbose($response);
			
            if (!empty($response['FaceMatches'])){
                foreach($response['FaceMatches'] as $match){
                    $matchingImage = $match['Face']['ExternalImageId'];
                    if ($matchingImage != $key){
                        $matchingKeys[$matchingImage] = $match;
                        echo sprintf("Image %s has a face that matches image %s \n", $key, $matchingImage);
                    }
                }
            }
        } catch (RekognitionException $e) {
            if ($verbose) 
                echo $e->getMessage() . "\n";
        }
		
        if (count($matchingKeys) == 0){
            echo "No matching faces detected\n";
        }

        return $matchingKeys;
    }
	
	/**
     * Calls Rekognition searchFacesByImage to search image for faces
     * that matches signtures in collection
     * Echos file keys with matching faces
     * @param string $key key to imagefile in S3 bucket
     * @return array keys of images with matching faces
     */
    function compareFaces($keyfile, $S3Bucket, $faceCollectionId, $bytefile)
    {
		
        echo sprintf("\n\nCompare for matching faces in target image %s \n", $keyfile);
        $matchingKeys = [];
		$verbose = false; // echo api response from AWA
		
		$options = array(
				'credentials'=>array('key' => access_key,
				'secret' => secret_key),
				'region' => 'eu-west-1',
				'version' => '2016-06-27',
			);
			
			$listfiles = listKeys($S3Bucket);
			//echo "<pre>";print_r($listfiles);exit;
			$rekognition = new RekognitionClient($options);
			
			foreach($listfiles as $key=>$val){
				$response = $rekognition->compareFaces([
					'SimilarityThreshold' => 90,
					'SourceImage' => [
						'Bytes'=> $bytefile
					],
					'TargetImage' => [
						'S3Object' => [
							'Bucket' => $val['Bucket'],
							'Name' => $val['Name'],
						]
					],
				]);
				
				echoVerbose($response);
			}
			
			echo "<pre>";print_r($response);exit;
		
		try {
			$options = array(
				'credentials'=>array('key' => access_key,
				'secret' => secret_key),
				'region' => 'eu-west-1',
				'version' => '2016-06-27',
			);
			
			$listfiles = listKeys($S3Bucket);
			
			$rekognition = new RekognitionClient($options);
			
			foreach($listfiles as $val){
				$response = $rekognition->compareFaces([
					'SimilarityThreshold' => 90,
					'SourceImage' => [
						'Bytes'=> $bytefile
					],
					'TargetImage' => [
						'S3Object' => [
							'Bucket' => $val['Bucket'],
							'Name' => $val['Name'],
						]
					],
				]);
				
				echoVerbose($response);
			}
			
			echo "<pre>";print_r($response);exit;
            
			
            if (!empty($response['FaceMatches'])){
                foreach($response['FaceMatches'] as $match){
                    $matchingImage = $match['Face']['ExternalImageId'];
                    if ($matchingImage != $key){
                        $matchingKeys[$matchingImage] = $match;
                        echo sprintf("Image %s has a face that matches image %s \n", $key, $matchingImage);
                    }
                }
            }
        } catch (RekognitionException $e) {
            if ($verbose) 
                echo $e->getMessage() . "\n";
        }
		
        /* if (count($matchingKeys) == 0){
            echo "No matching faces detected\n";
        }

        return $matchingKeys; */
    }
	
	/**
     * list object keys from bucket
     * @return array key = object key, value = last modified
     */
    function listKeys($S3Bucket)
    {
        $list = [];
		
		// instantiate S3 class
		$s3 = S3Client::factory(array(
		'credentials'=>array('key' => access_key,
		'secret' => secret_key),
		'region' => 'eu-west-1',
		'version'	=> 'latest'
		));
	
        try {
            $result = $s3->listObjects(['Bucket' => $S3Bucket]);
            $contents = isset($result['Contents']) ? $result['Contents'] : [];
            foreach ($contents as $object) {
                $key = $object['Key'];
                $list[$key] = [
                    'Bucket' => $S3Bucket, 
                    'Name' => $key,
                    'Modified' => $object['LastModified']
                ];
            }
        } catch (S3Exception $e) {
            echo "Oups, this failed. Have You configured Security Crentials and S3 Bucket correctly?\n\n";
            echo $e->getMessage() . "\n";

            exit(1);
        }

        return $list;
    }
	
	/**
     * helper function to echo response arrays
     * @param array $response response to echo
     * @return void
     */
    function echoVerbose($response)
    {
		$verbose = false; // echo api response from AWA
		
        if ($verbose){
            echo "\n\n";
            print_r($response);
            echo "\n\n";
        }
    }
?>
<form action="" method="post" enctype="multipart/form-data">
    <p>Choose an image to send to Rekognition (1MB max):</p>
    <p><input type="file" name="fileToUpload" id="fileToUpload" required /></p>
    <input type="submit" value="Upload Image" name="submit" />
</form>