#!/bin/bash
# Nautilus Script for Pdf-Signer
# Stefan Pampel <stefan.pampel@polyformal.de>
# once the script installed you can sign PDF's easyly
#
# to install: 
# - modify PS_PATH that it points to the PdfSigner directory
# - modify KEY that it points to your signing key
# - modify PASSPHRASE with your passphrase
# - for security reason chmod 600 this file 
FILES="`echo ${NAUTILUS_SCRIPT_SELECTED_FILE_PATHS}`"
PS_PATH=/path/to/PdfSigner
KEY=/path/to/key.p12
PASSPHRASE="top_secret_password"
REASON="Ich bin der Verfasser des Dokumentes."
LOCATION="World"
export CLASSPATH=$PS_PATH/itext-1.4.2.jar:$PS_PATH/sign.class:.
cd $PS_PATH
for FILE in $FILES ; do
#	java sign $KEY "${PASSPHRASE}" "${FILE}" `basename "${FILE}" .pdf`.sec "${REASON}" "${LOCATION}"
	java sign $KEY "${PASSPHRASE}" "${FILE}" "${FILE}"_sec "${REASON}" "${LOCATION}" 
	mv "{$FILE}"_sec `basename "{$FILE}" .pdf_sec`_sign.pdf
done

