using System;
using System.Collections.Generic;
using System.Linq;
using System.Text;

namespace ORK3.Model
{
    public class Configuration
    {
        public static readonly Configuration Config = new Configuration();

        public readonly string ConfigDir = System.IO.Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.ApplicationData), System.Windows.Forms.Application.ProductName);
        public readonly string ConfigPath = System.IO.Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.ApplicationData), System.Windows.Forms.Application.ProductName, "Config.json");

        private Dictionary<string, string> Settings;

        private Configuration()
        {
        }

        public void Initialize()
        {
            if (Settings == null)
            {
                Settings = new Dictionary<string, string>();
                if (!System.IO.Directory.Exists(ConfigDir)) System.IO.Directory.CreateDirectory(ConfigDir);
                if (!System.IO.File.Exists(ConfigPath))
                {
                    Settings["UserName"] = "";
                    Settings["Password"] = "";
                    Settings["ServiceURL"] = "http://www.amtgard-wl.com/ork3/dev/orkservice/Authorization/AuthorizationService.php?wsdl";
                    WriteConfig();
                }
                ReadConfig();
            }
        }

        public Dictionary<string, string> GetConfig
        {
            get
            {
                return Settings;
            }
        }

        public string this[string key] {
            get
            {
                lock (Settings)
                {
                    if (Settings.ContainsKey(key)) return Settings[key];
                    throw new Exception("Configuration does not contain the key " + key);
                }
            }
            set
            {
                lock (Settings)
                {
                    Settings[key] = value;
                }
            }
        }

        private void ReadConfig()
        {
            lock (Settings)
            {
                System.IO.MemoryStream ms = new System.IO.MemoryStream(Encoding.Unicode.GetBytes(System.IO.File.ReadAllText(ConfigPath)));
                System.Runtime.Serialization.Json.DataContractJsonSerializer ser = new System.Runtime.Serialization.Json.DataContractJsonSerializer(typeof(Dictionary<string, string>));
                Settings = (Dictionary<string, string>)ser.ReadObject(ms);
            }
        }

        public void WriteConfig()
        {
            lock (Settings)
            {
                System.IO.MemoryStream ms = new System.IO.MemoryStream();
                System.Runtime.Serialization.Json.DataContractJsonSerializer ser = new System.Runtime.Serialization.Json.DataContractJsonSerializer(typeof(Dictionary<string, string>));
                ser.WriteObject(ms, Settings);
                System.IO.File.WriteAllText(ConfigPath, Encoding.Default.GetString(ms.ToArray()));
            }
        }

    }
}
