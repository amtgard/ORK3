using System;
using System.Collections.Generic;
using System.Linq;
using System.Text;
using System.Reflection;

namespace TestServices
{
    public delegate bool TestMethodHandler();

    public partial class UnitTest
    {
        public int pass, total;

        public AuthorizationService.AuthorizationService authsvc;
        public ReportService.ReportService reportsvc;
        public KingdomService.KingdomService kingdomsvc;
        public ParkService.ParkService parksvc;

        public UnitTest()
        {
            authsvc = new AuthorizationService.AuthorizationService();
            reportsvc = new ReportService.ReportService();
            kingdomsvc = new KingdomService.KingdomService();
            parksvc = new ParkService.ParkService();
        }

        public void RunTests(string Prefix = null)
        {
            Type t = this.GetType();

            MethodInfo[] methods = t.GetMethods();

            ConsoleColor cc = Console.ForegroundColor;

            foreach (MethodInfo mi in methods)
            {
                if (mi.Name == "RunTests") continue;
                if (Prefix != null)
                {
                    if (!mi.Name.StartsWith(Prefix))
                    {
                        continue;
                    }
                }

                TestMethodHandler method;
                try
                {
                    method = (TestMethodHandler)Delegate.CreateDelegate(typeof(TestMethodHandler), this, mi);
                }
                catch
                {
                    continue;
                }

                total++;
                Console.Write(method.Method.Name + ": ");
                try
                {
                    if (method())
                    {
                        Console.ForegroundColor = ConsoleColor.Green;
                        Console.WriteLine("PASS".PadLeft(72 - method.Method.Name.Length, '.'));
                        pass++;
                    }
                    else
                    {
                        Console.ForegroundColor = ConsoleColor.Red;
                        Console.WriteLine("FAIL".PadLeft(72 - method.Method.Name.Length, '.'));
                    }
                }
                catch (Exception e)
                {
                    Console.ForegroundColor = ConsoleColor.Red;
                    Console.WriteLine("FAIL".PadLeft(72 - method.Method.Name.Length, '.'));
                    Console.ForegroundColor = ConsoleColor.Yellow;
                    Console.WriteLine(e.ToString());
                }
                finally
                {
                    Console.ForegroundColor = cc;
                }
            }

            Console.WriteLine("\n\n");
            if (pass == total)
            {
                Console.ForegroundColor = ConsoleColor.Green;
            }
            else
            {
                Console.ForegroundColor = ConsoleColor.Red;
            }
            Console.Write(pass.ToString().PadLeft(72));
            Console.ForegroundColor = cc;
            Console.WriteLine(" / " + total.ToString());

            cc = Console.ForegroundColor;
            if (pass < total)
            {
                Console.ForegroundColor = ConsoleColor.Red;
            }
            else
            {
                Console.ForegroundColor = ConsoleColor.Green;
            }

            Console.WriteLine(string.Format("{0:0.00%}", ((double)pass / (double)total)).PadLeft(72));

            Console.ReadKey();
        }

    }
}
