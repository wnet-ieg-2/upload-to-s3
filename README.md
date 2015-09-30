# upload-to-s3
The Upload to S3 WordPress plugin automatically adds to specific text input fields the ability to select a file from your local machine, upload it directly to an AWS S3 bucket that the user has access to, and populate the text field with the public URL of the uploaded file. The file is never saved on the WordPress server. This is useful for working with large media files, such as podcasts and videos, that you want to store remotely without filling up your server disk.

## Usage
Add the CSS class 'upload_to_s3' to any text input and the S3 file uploader will automatically appear connected to that text field. Selecting and uploading a file will put the file in your S3 bucket and will put the public URL of your file into the text field. You can apply the class to multiple text input fields on a page, and each will work independently.

### Settings
Plugin setup is in the WordPress 'Settings' menu under 'Upload to S3 Settings'.  The S3 file uploader will not appear unless the name of an AWS S3 bucket is saved in the settings and some access credentials are entered and saved.

### Shared or individual access credentials

Upload access to your bucket is setup in AWS via the AWS 'IAM' system. Any user(s) who have access to the bucket will need an IAM account and a key/secret pair. You may create a single user and enter that key pair in the fields above, or you may setup indiviudal IAM accounts for any users you want to allow to upload to the S3 bucket. In this latter case, the user's key pair will be maintainted in his or her own profile page.

If there is no key pair set (either the global pair if you use shared credentials, or the individual users' key pair if you use individual credentials), the S3 file uploader will not appear.

### AWS S3 Bucket setup
Setting up an Amazon Web Services (AWS) account and using their S3 service to create a 'bucket' for file storage is beyond the scope of this documentation.  Creating and managing users for AWS services using their Identity and Access Management (IAM) system is also beyond the scope of this documentation.  http://aws.amazon.com is your best resource for performing that end of the setup and finding how to do so.
