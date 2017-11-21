export CLASSPATH=itext-2.0.4.jar:sign.class:.

all: sign.class

sign.class: sign.java
	javac sign.java

test:
	java sign cert-and-private_key.pfx Password input.pdf output.pdf "Bin der Autor" "Wien"


