import com.lowagie.text.pdf.*;
import com.lowagie.text.*;
//import org.bouncycastle.x509.*;
import java.security.cert.*;
//import java.security.*;
import java.security.KeyStore;
import java.security.PrivateKey;
import java.io.*;
import java.lang.System;

//Ahmad Gharbeia, 2009-09-15: added more descriptive error message upon exiting

public class sign {
 
public static void main(String[] args) 
{
  PdfReader reader;
  PdfSignatureAppearance sap;
  PdfStamper stp;
  FileOutputStream fout;
  PrivateKey key;
  Certificate[] chain;
  KeyStore ks;

  try
  {
    ks = KeyStore.getInstance("pkcs12");
    ks.load(new FileInputStream(args[0]), args[1].toCharArray());
  }
  catch(Exception e)
  {
    System.out.print("Error loading certificate store: " + e + "\n");
    return;
  }

  try
  {
    String alias = (String)ks.aliases().nextElement();
    key = (PrivateKey)ks.getKey(alias, args[1].toCharArray());
    chain = ks.getCertificateChain(alias);
  }
  catch(Exception e)
  {
    System.out.print("Problems loading key or chain: " + e + "\n");
    return;
  }

  try
  {
    reader = new PdfReader(args[2]);
    fout = new FileOutputStream(args[3]);
  }
  catch(Exception e)
  {
    System.out.print("Problems initialising PDF reader: " + e + "\n");
    return;
  }

  try
  {
    stp = PdfStamper.createSignature(reader, fout, '\0', new File("/tmp"));
    sap = stp.getSignatureAppearance();
  }
  catch(Exception e)
  {
    System.out.print("Problems creating: " + e + "\n");
    return;
  }

  try
  {
    sap.setCrypto(key, chain, null, PdfSignatureAppearance.WINCER_SIGNED);
  }
  catch(Exception e)
  {
    System.out.print("Problem setting crypto: " + e + "\n");
    return;
  }


  try
  {
    sap.setReason(args[4]);
    sap.setLocation(args[5]);
    sap.setContact(args[6]);
  }
  catch(Exception e)
  {
    System.out.print("Problem setting settings: " + e + "\n");
    return;
  }


  try
  {
    // comment next line to have an invisible signature
    sap.setVisibleSignature(new Rectangle(Integer.valueOf(args[7]).intValue(), Integer.valueOf(args[8]).intValue(), Integer.valueOf(args[9]).intValue(), Integer.valueOf(args[10]).intValue()), Integer.valueOf(args[11]).intValue(), null);

    //Seems to be deprecated:
    //sap.setCertified(true);

    //We tried to encrypt it, but it doesnt work:
    //stp.setEncryption(true,null,null,4 + 2048);
    //PdfEncryptor enc; // = new PdfEncryptor();
    //enc.encrypt(reader,fout,true,null,null, AllowPrinting | AllowScreenReaders);
  }
  catch(Exception e)
  {
    System.out.print("Problem Doing rest: " + e + "\n");
    return;
  }

  System.out.print("Done.\n");

  try
  {
    stp.close();
  }
  catch(Exception e)
  {
    System.out.print("Problem with closing: " + e + "\n");
  }

}

}
