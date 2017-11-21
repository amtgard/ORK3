CAcert PDF Signer

Author: philipp@cacert.org

CAcert PDF Signer is based on iText from http://www.lowagie.com/iText/

This compiled version of PDF Signer requires Java 1.5. It is unknown, whether it is possible to run it on Java 1.4

The CAcert PDF Signer is a itext based commandline PDF signing application, which can be run automatically.
It takes a PKCS#12 packaged certificate, a PDF file, and generates an output PDF file, which has the digital signature included.


Run it:

java sign <Zertifikat.pkcs12> <Password> <Original.pdf> <Output.pdf> <Reason> <Place>

Example:

java sign my_private_key.pfx Password Secure_Web_Development.pdf output.pdf "Bin der Autor" "Wien"


