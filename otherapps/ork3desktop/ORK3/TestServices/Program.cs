using System;
using System.Collections.Generic;
using System.Linq;
using System.Text;

namespace TestServices
{
    class Program
    {
        static void Main(string[] args)
        {
            UnitTest ut = new UnitTest();
            ut.RunTests("Park_GetParkShortInfo");
        }
    }
}
