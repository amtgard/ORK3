#!/bin/bash

export CLASSPATH=bcprov-jdk16-137.jar:itext-2.0.4.jar:sign.class:.

echo "Usage: $0 <Certifiate.pkcs12> <Password> <Original.pdf> <Output.pdf> <Reason> <Place> <Contact> <x1> <x2> <y1> <y2> <Page>"

java sign ${@}

