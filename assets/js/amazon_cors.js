jQuery(document).ready(function($) {

// The functions below adapted from Carson McDonald -- 
// http://www.ioncannon.net/programming/1539/direct-browser-uploading-amazon-s3-cors-fileapi-xhr2-and-signed-puts/

function U2S3AWScreateCORSRequest(method, url) {
  var xhr = new XMLHttpRequest();
  if ("withCredentials" in xhr) 
  {
    xhr.open(method, url, true);
  } 
  else if (typeof XDomainRequest != "undefined") 
  {
    xhr = new XDomainRequest();
    xhr.open(method, url);
  } 
  else 
  {
    xhr = null;
  }
  return xhr;
}

function U2S3convertToSlug(Text) {
    return Text.replace(/ /g,'_').replace(/[^\w_.-]+/g,'');
}

/**
 * Execute the given callback with the signed response.
 */
function U2S3AWSexecuteOnSignedUrl(file, callback) {
  $.ajax({
    type: "GET",
    url: ajaxurl,
    data:{
      'action': 'upload_to_s3_sign_aws_request',
      'slug': U2S3convertToSlug(file.name),
      'fileinfo': file.type
    },
    dataType:'text',
    success: function(response){
      console.log(response);
      callback(decodeURIComponent(response));
    },
    error: function(response){
      console.log(response);
    }
  });
}


function U2S3AWSuploadFile(thisobj) {
  var that = thisobj.parents("div.s3uploader");
  $('.statusblock', that).show(); 
  $('.upload_to_s3', that).val('');
  var file = $('.file_to_upload', that).get(0).files[0];
  U2S3AWSexecuteOnSignedUrl(file, function(signedURL){
    U2S3AWSuploadToS3(file, signedURL, that);
  });
}

/**
 * Use a CORS call to upload the given file to S3. Assumes the url
 * parameter has been signed and is accessible for upload.
 */
function U2S3AWSuploadToS3(file, url, obj) {
  var slug = U2S3convertToSlug(file.name);
  var xhr = U2S3AWScreateCORSRequest('PUT', url);
  if (!xhr) {
    U2S3AWSsetProgress(0, 'CORS not supported', obj);
  }
  else {
    xhr.onload = function() {
      if(xhr.status == 200)
      {
        U2S3AWSsetProgress(100, 'Completed.', obj);
        var publicurl = 'https://s3.amazonaws.com/' + wp_script_vars.aws_bucket;
        if (wp_script_vars.proxy_host) {
          publicurl = wp_script_vars.proxy_host;
        }
        if (wp_script_vars.aws_folder) {
          publicurl = publicurl + '/' + wp_script_vars.aws_folder;     
        }
        
        publicurl = publicurl + '/' + slug;
        $('.upload_to_s3', obj).val(publicurl);
        $('.upload_to_s3', obj).prop({readOnly: true});
        $('.file_to_upload', obj).prop({disabled: true});
        $('.uploadblock', obj).prepend('<span class="make_rw"><a>Change URL</a> <i><b>Warning:</b> Manually editing the URL removes any connection to the file you just uploaded.</i></span>');
        $('.make_rw a', obj).click(function(event){
          event.preventDefault();
          U2S3resetUploadStatus($(this));
        });
        var sizestr = file.size ? file.size : file.fileSize;
        $('.s3-total-bytes', obj).text(sizestr + " bytes");
      }
      else
      {
        U2S3AWSsetProgress(0, 'Upload error: ' + xhr.status, obj);
      }
    };

    xhr.onerror = function() {
      U2S3AWSsetProgress(0, 'XHR error:' + xhr.statusText, obj);
    };

    xhr.upload.onprogress = function(e) {
      if (e.lengthComputable) 
      {
        var percentLoaded = Math.round((e.loaded / e.total) * 100);
        $('.s3-upload-progress', obj).attr({
        value: e.loaded,
        max: e.total
        });
        U2S3AWSsetProgress(percentLoaded, percentLoaded == 100 ? 'Finalizing.' : 'Uploading.', obj);
      }
    };

    xhr.setRequestHeader('Content-Type', file.type);
    xhr.setRequestHeader('x-amz-acl', 'public-read');
    xhr.send(file);
  }
}

function U2S3AWSsetProgress(percentage, statusLabel, obj) {
  $('.s3-percent-transferred', obj).text(percentage);
  $('.s3-post-upload-status', obj).text(statusLabel);
}

function U2S3resetUploadStatus(obj) {
  var that = obj.parents("div.s3uploader");
  $('.upload_to_s3', that).prop({readOnly: false});
  $('.make_rw', that).remove();
  $('.file_to_upload', that).prop({disabled: false});
  U2S3AWSsetProgress(0, 'New file', that);
  $('.s3-upload-progress', that).attr({
    value: 0,
    max:0 
  });
  $('.s3-total-bytes', that).text('');
}

if (wp_script_vars.aws_bucket) {
  $("input[type='text'].upload_to_s3").wrap('<div class="s3uploader"></div>');
  $("div.s3uploader").append('<div class="uploadblock"><p>Select a file to upload to your S3 bucket: <input type=file class="file_to_upload">&nbsp;<button class="button">Upload the selected file</button></p><div class="statusblock" style="display:none;"><span class="status">Upload status: <span class="s3-post-upload-status"></span> </span><span class="progress">Progress: <span class="s3-percent-transferred"></span>% <progress class="s3-upload-progress"></progress> <span class="s3-total-bytes"></span></span></div></div>');
}
$('.s3uploader button.button').click(function(event) {
  event.preventDefault();
  U2S3AWSuploadFile( $(this) );
  });


});

